<?php

  /**
   * Purpose: Sync Albums in steps, reload script for each step / log each step
   *
   * Steps
   * - Clean-up session and log file              ?action=syncAlbum&step=clean-up
   * - Load all photo albums > save in session    ?action=syncAlbum&step=loadAlbums
   * - Load all drive folder > save in session    ?action=syncAlbum&step=loadFolders
   * - Loop drive folder                          ?action=syncAlbum&step=loopFolders
   *   - if no folder > trash
   *   - if no match with album > trash
   *   - if name different > rename
   * - Loop photo albums                          ?action=syncAlbum&step=loopAlbums
   *   - for each                                 ?action=syncAlbum&step=loopAlbums&album=n
   *     - create in drive if not exists
   *     - load all photos > save in session
   *     - load all files > save in session
   *     - loop drive files in batches            ?action=syncAlbum&step=loopAlbums&album=n&file=n
   *       - if not match in photos > trash
   *       - if name different > rename
   *     - loop photos in batches                 ?action=syncAlbum&step=loopAlbums&album=n&photo=n
   *       - create in drive if not exists
   *  - Completed                                 ?action=syncAlbum&step=completed
   */

  /**
   * Log functions
   */

  function logText($text) {
    $file = fopen('syncAlbums.log', 'a+');
    fwrite($file, date('d.m.Y H:i:s') . ' - ' . $text . "\r\n");
    fclose($file);
  }

  /**
   * State management
   */

  $stateStep = isset($_GET['step']) ? $_GET['step'] : null;
  $stateAlbum = isset($_GET['album']) ? intval($_GET['album']) : null;
  $stateFile = isset($_GET['file']) ? intval($_GET['file']) : null;
  $statePhoto = isset($_GET['photo']) ? intval($_GET['photo']) : null;
  function nextState($step, $album = null, $file = null, $photo = null) {
    $uri = 'index.php?action=syncAlbums&step=' . $step
         . ($album ? '&album=' . $album : '')
         . ($file ? '&file=' . $file : '')
         . ($photo ? '&photo=' . $photo : '');
    //logText('Next state: ' . $uri);
    header('Refresh: 0; url=' . $uri);
  }

  /**
   * No step
   */

  if (!$stateStep) nextState('clean-up');

  /**
   * Clean-up
   */

  if ($stateStep === 'clean-up') {

    // Clear session
    unset($_SESSION['albums']);
    unset($_SESSION['folders']);
    unset($_SESSION['files']);
    unset($_SESSION['photos']);
    unset($_SESSION['backupFolder']);

    // Delete log file
    if (file_exists('syncAlbums.log')) unlink('syncAlbums.log');

    // Log
    logText('Clean-up done');

    // Next state
    nextState('loadAlbums');

  }

  /**
   * Load albums
   */

  if ($stateStep === 'loadAlbums') {

    // Load albums, add ident
    $albums = [];
    foreach ($photos->getAlbums() as $album) {
      $ident = 'album/' . $album['id'];
      $albums[$ident] = $album;
    }

    // Store albums in session
    $_SESSION['albums'] = $albums;

    // Log
    logText(count($albums) . ' album' . (count($albums) !== 1 ? 's' : '') . ' found in Google Photos');

    // Next state
    nextState('loadFolders');

  }

  /**
   * Load folders
   */

  if ($stateStep === 'loadFolders') {

    // Get backup folder
    $folderId = $drive->ensureFolder(DEFAULT_BACKUP_FOLDER_ALBUMS)['id'];
    $_SESSION['backupFolder'] = $folderId;

    // Load unqiue sub folders, add ident
    $folders = [];
    $foldersSearch = $drive->search(['q' => 'trashed=false and "' . $folderId . '" in parents', 'orderBy' => 'name', 'pageSize' => 1000]);
    foreach ($foldersSearch as $folder) {
      $ident = (!$folder['description'] || $folder['description'] === '' || array_key_exists($folder['description'], $folders)) ? $folder['id'] : $folder['description'];
      $folders[$ident] = $folder;
    }

    // Store folders in session
    $_SESSION['folders'] = $folders;

    // Log
    logText(count($folders) . ' folder' . (count($folders) !== 1 ? 's' : '') . ' found in Google Drive');

    // Next state
    nextState('loopFolders');

  }

  /**
   * Loop folders
   **/

  if ($stateStep === 'loopFolders') {

    foreach ($_SESSION['folders'] as $ident => $folder) {

      // No match or no folder > trash
      if (!isset($_SESSION['albums'][$ident]) || $folder['mimeType'] !== 'application/vnd.google-apps.folder') {
        $trash = $drive->trash($folder['id']);
        if ($trash) logText('Folder "' . $folder['name'] . '" trashed');
          else logText('[ERROR] Failed to trash folder "' . $folder['name'] . '"');

      // Name changed > rename
      } else if ($folder['name'] !== $_SESSION['albums'][$ident]['name']) {
        $rename = $drive->rename($folder['id'], $_SESSION['albums'][$ident]['name']);
        if ($rename) logText('Folder "' . $folder['name'] . '" renamed to "' . $_SESSION['albums'][$ident]['name'] . '"');
          else logText('[ERROR] Failed to rename folder "' . $folder['name'] . '"');
      }

    }

    // Next state
    nextState('loopAlbums');

  }

  /**
   * Loop albums
   */

  if ($stateStep === 'loopAlbums') {

    // No album state
    if ($stateAlbum === null) {
      nextState('loopAlbums', 1);

    // Album state above number of albums
    } else if ($stateAlbum > count($_SESSION['albums'])) {

      nextState('completed');

    // Loop album
    } else {

      // Get album keys
      $albumKeys = array_keys($_SESSION['albums']);

      // Get current key = ident
      $ident = $albumKeys[$stateAlbum - 1];

      // Get current album
      $album = $_SESSION['albums'][$ident];

      // Create folder is not exists
      if (!isset($_SESSION['folders'][$ident])) {
        $newFolder = $drive->createFolder([
          'name' => $album['name'],
          'parents' => [$_SESSION['backupFolder']],
          'description' => $ident
        ]);
        if ($newFolder) {
          logText('Folder "' . $album['name'] . '" created');
          $_SESSION['folders'][$ident] = $newFolder;
        } else logText('[ERROR] Failed to create folder "' . $album['name'] . '"');
      }
      $folder = $_SESSION['folders'][$ident];

      // Load all photos, add identifier and filename > log
      $allPhotos = [];
      $getPhotos = $photos->getPhotos($album['id']);
      $photoNo = 0;
      foreach ($getPhotos as $currentPhoto) {
        $photoNo++;
        if (substr($currentPhoto['mimeType'], 0, 6) === 'image/') {
          $ext = str_replace('jpeg', 'jpg', substr($currentPhoto['mimeType'], 6));
          $currentPhoto['fileName'] = $album['name'] . ' #' . str_pad($photoNo, strlen(count($getPhotos)), '0', STR_PAD_LEFT) . '.' . $ext;
          $photoIdent = 'album/' . $album['id'] . '/photo/' . $currentPhoto['id'] . '/updated/' . $currentPhoto['updated'];
          $allPhotos[$photoIdent] = $currentPhoto;
        } else {
          logText('Skip file ' . $currentPhoto['name']);
        }
      }
      $_SESSION['photos'] = $allPhotos;
      logText(count($allPhotos) . ' photo' . (count($allPhotos) !== 1 ? 's' : '') . ' found in album "' . $album['name'] . '"');

      // Load all files, add identifier > log
      $allFiles = [];
      $filesSearch = $drive->search(['q' => 'trashed=false and "' . $folder['id'] . '" in parents', 'orderBy' => 'name', 'pageSize' => 1000]);
      foreach ($filesSearch as $currentFile) {
        $ident = (!$currentFile['description'] || $currentFile['description'] === '' || array_key_exists($currentFile['description'], $allFiles)) ? $currentFile['id'] : $currentFile['description'];
        $allFiles[$ident] = $currentFile;
      }
      $_SESSION['files'] = $allFiles;
      logText(count($allFiles) . ' file' . (count($allFiles) !== 1 ? 's' : '') . ' found in folder "' . $folder['name'] . '"');

      // Next state
      nextState('loopFiles', $stateAlbum, 1);

    }

  }

  /**
   * Loop files
   */

  if ($stateStep === 'loopFiles') {

    // No file state
    if ($stateFile === null) {
      nextState('loopFiles', $stateAlbum, 1);

    // File state above number of files
    } else if ($stateFile > count($_SESSION['files'])) {
      nextState('loopPhotos', $stateAlbum, null, 1);

    // Loop files
    } else {
      $start = time();
      $keys = array_keys($_SESSION['files']);
      while ($stateFile <= count($_SESSION['files']) && time()-$start < 20) {

        // Get current file
        $ident = $keys[$stateFile - 1];
        $file = $_SESSION['files'][$ident];

        // No match > trash
        if (!isset($_SESSION['photos'][$ident])) {

          $trash = $drive->trash($file['id']);
          if ($trash) logText('File "' . $file['name'] . '" trashed');
            else logText('[ERROR] Failed to trash file "' . $file['name'] . '"');

        // Name changed > rename
        } else if ($file['name'] !== $_SESSION['photos'][$ident]['fileName']) {
          $rename = $drive->rename($file['id'], $_SESSION['photos'][$ident]['fileName']);
          if ($rename) logText('File "' . $file['name'] . '" renamed to "' . $_SESSION['photos'][$ident]['fileName'] . '"');
            else logText('[ERROR] Failed to rename file "' . $file['name'] . '"');
        }

        // Next file
        $stateFile += 1;

      }
      if ($stateFile > count($_SESSION['files'])) nextState('loopPhotos', $stateAlbum, null, 1);
      else nextState('loopFiles', $stateAlbum, $stateFile);
    }

  }

  /**
   * Loop photos
   */

  if ($stateStep === 'loopPhotos') {

    // No photo state
    if ($statePhoto === null) {
      nextState('loopPhotos', $stateAlbum, null, 1);

    // Photo state above number of photos
    } else if ($statePhoto > count($_SESSION['photos'])) {
      nextState('loopAlbums', $stateAlbum += 1);

    // Loop photos
    } else {
      $start = time();
      $keys = array_keys($_SESSION['photos']);
      $keysAlbum = array_keys($_SESSION['albums']);
      $folderId = $_SESSION['folders'][$keysAlbum[$stateAlbum - 1]]['id'];
      while ($statePhoto <= count($_SESSION['photos']) && time()-$start < 20) {

        // Get current photo
        $ident = $keys[$statePhoto - 1];
        $photo = $_SESSION['photos'][$ident];

        // Create photo is not exists
        if (!isset($_SESSION['files'][$ident])) {

          $newPhoto = $drive->createFile([
            'name' => $photo['fileName'],
            'parents' => [$folderId],
            'description' => $ident
          ], file_get_contents($photo['uri'] . '?imgmax=9999'));
          if ($newPhoto) {
            logText('File "' . $photo['fileName'] . '" created');
            $_SESSION['files'][$ident] = $newPhoto;
          } else logText('[ERROR] Failed to create file "' . $photo['fileName'] . '"');


        }

        // Next file
        $statePhoto += 1;

      }
      if ($statePhoto > count($_SESSION['photos'])) nextState('loopAlbums', $stateAlbum += 1);
      else nextState('loopPhotos', $stateAlbum, null, $statePhoto);
    }

  }

  /**
   * Completed
   */

  if ($stateStep === 'completed') {

    // Clear session
    unset($_SESSION['albums']);
    unset($_SESSION['folders']);
    unset($_SESSION['files']);
    unset($_SESSION['photos']);
    unset($_SESSION['backupFolder']);
    logText('Cleaned-up the session');

    logText('Synchronization completed successfully');

    rename('syncAlbums.log', 'syncAlbums - ' . date('d.m.Y H:i:s') . '.log');

    echo '<p><b>Synchronization completed successfully</b></p>'
       . '<p><a href="index.php">go to index page</a></p>';

  }

?>
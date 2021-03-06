<?php

  /**
   * Purpose: Trash file
   * Input: <string> $id
   * Output: <array> $file
   */

  // Check input
  if (!is_string($id)) throw new Exception('Argument $id must be a string');

  // Trash file
  $file = $this->updateFile($id, ['trashed' => true]);

?>
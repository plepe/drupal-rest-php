<?php
function drupal_load_rest_export ($drupal, $path, $options = array()) {
  $ch = curl_init();
  $total = array();

  if (!array_key_exists('page_size', $options)) {
    $options['page_size'] = 0;
  }

  $page = 0;
  do {
    $sep = strpos($path, '?') === false ? '?' : '&';
    curl_setopt($ch, CURLOPT_URL, "{$drupal['url']}{$path}{$sep}page={$page}&_format=json");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "{$drupal['user']}:{$drupal['pass']}");
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    if (array_key_exists('verbose', $drupal) && $drupal['verbose']) {
      curl_setopt($ch, CURLOPT_VERBOSE, true);
    }

    $result = curl_exec($ch);
    if ($result[0] !== '[') {
      print "Error loading: " . $result;
      exit(1);
    }

    $result = json_decode($result, true);

    if (!$result || !sizeof($result)) {
      $result = array();
    }

    $total = array_merge($total, $result);
    $page++;
  } while(sizeof($result) > 0 && sizeof($result) === $options['page_size']);

  return $total;
}

function drupal_node_get ($drupal, $id, $options = array()) {
  $ch = curl_init();

  curl_setopt($ch, CURLOPT_URL, "{$drupal['url']}/node/{$id}?_format=json");
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_USERPWD, "{$drupal['user']}:{$drupal['pass']}");
  curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
  if (array_key_exists('verbose', $drupal) && $drupal['verbose']) {
    curl_setopt($ch, CURLOPT_VERBOSE, true);
  }

  $result = curl_exec($ch);
  if ($result[0] !== '{') {
    print "Error loading '/node/{$id}': " . $result;
    exit(1);
  }

  $result = json_decode($result, true);

  if (!array_key_exists('nid', $result)) {
    print "Error loading '/node/{$id}': ";
    print_r($result);
    exit(1);
  }

  if (!$result || !sizeof($result)) {
    return null;
  }

  if (isset($options['embed_field'])) {
    foreach($options['embed_field'] as $field) {
      foreach ($result[$field] as $index => $ref) {
        $result[$field][$index]['data'] = drupal_node_get($ref['target_id']);
      }
    }
  }

  return $result;
}

function drupal_node_save ($drupal, $nid, $content) {
  $current_node = null;

  if ($nid) {
    $current_node = drupal_node_get($drupal, $nid);
  }

  // check for embedded fields
  foreach ($content as $field_id => $_d1) {
    foreach ($_d1 as $index => $_d2) {
      if (array_key_exists('data', $_d2)) {
        if ($current_node && array_key_exists($index, $current_node[$field_id])) {
          $current_embedded = drupal_node_get($drupal, $current_node[$field_id][$index]['target_id']);

          if (drupal_diff($_d2['data'], $current_embedded)) {
            drupal_save_node($drupal, $current_node[$field_id][$index]['target_id'], $_d2['data']);
          }

          $content[$field_id][$index] = ['target_id' => $current_node[$field_id][$index]['target_id']];
        }
        else {
          $content[$field_id][$index] = ['target_id' => drupal_save_node($drupal, null, $_d2['data'])];
        }

      }
    }
  }

  $ch = curl_init();

  if ($nid) {
    curl_setopt($ch, CURLOPT_URL, "{$drupal['url']}/node/{$nid}?_format=json");
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
  }
  else {
    curl_setopt($ch, CURLOPT_URL, "{$drupal['url']}/entity/node?_format=json");
    curl_setopt($ch, CURLOPT_POST, true);
  }

  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($content));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-type: application/json',
  ]);
  curl_setopt($ch, CURLOPT_USERPWD, "{$drupal['user']}:{$drupal['pass']}");
  curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
  if (array_key_exists('verbose', $drupal) && $drupal['verbose']) {
    curl_setopt($ch, CURLOPT_VERBOSE, true);
  }

  $result = curl_exec($ch);
  if ($result[0] !== '{') {
    print $result;
    exit(1);
  }

  $result = json_decode($result, true);

  if (!array_key_exists('nid', $result)) {
    print_r($result);
    exit(1);
  }

  return $result;
}

function drupal_node_remove ($drupal, $nid) {
  $ch = curl_init();

  curl_setopt($ch, CURLOPT_URL, "{$drupal['url']}/node/{$nid}?_format=json");
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');

  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-type: application/json',
  ]);
  curl_setopt($ch, CURLOPT_USERPWD, "{$drupal['user']}:{$drupal['pass']}");
  curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
  if (array_key_exists('verbose', $drupal) && $drupal['verbose']) {
    curl_setopt($ch, CURLOPT_VERBOSE, true);
  }

  $result = curl_exec($ch);

  return true;
}

function drupal_paragraph_save ($drupal, $id, $content) {
  $current_node = null;

  $ch = curl_init();

  if ($id) {
    curl_setopt($ch, CURLOPT_URL, "{$drupal['url']}/entity/paragraph/{$id}?_format=json");
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
  }
  else {
    curl_setopt($ch, CURLOPT_URL, "{$drupal['url']}/entity/paragraph?_format=json");
    curl_setopt($ch, CURLOPT_POST, true);
  }

  curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($content));
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-type: application/json',
  ]);
  curl_setopt($ch, CURLOPT_USERPWD, "{$drupal['user']}:{$drupal['pass']}");
  curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
  if (array_key_exists('verbose', $drupal) && $drupal['verbose']) {
    curl_setopt($ch, CURLOPT_VERBOSE, true);
  }

  $result = curl_exec($ch);
  if ($result[0] !== '{') {
    print $result;
    exit(1);
  }

  $result = json_decode($result, true);

  if (!array_key_exists('id', $result)) {
    print_r($result);
    exit(1);
  }

  return $result;
}

function drupal_paragraph_get ($drupal, $id) {
  $current_node = null;

  $ch = curl_init();

  curl_setopt($ch, CURLOPT_URL, "{$drupal['url']}/entity/paragraph/{$id}?_format=json");
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-type: application/json',
  ]);
  curl_setopt($ch, CURLOPT_USERPWD, "{$drupal['user']}:{$drupal['pass']}");
  curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
  if (array_key_exists('verbose', $drupal) && $drupal['verbose']) {
    curl_setopt($ch, CURLOPT_VERBOSE, true);
  }

  $result = curl_exec($ch);
  if ($result[0] !== '{') {
    print $result;
    exit(1);
  }

  $result = json_decode($result, true);

  if (!array_key_exists('id', $result)) {
    print_r($result);
    exit(1);
  }

  return $result;
}

/**
 * drupal_path: entity_type_id/bundle/field_name
 */
function drupal_file_upload ($drupal, $file, $drupal_path) {
  $ch = curl_init();

  $file_content = file_get_contents($file);

  curl_setopt($ch, CURLOPT_URL, "{$drupal['url']}/file/upload/{$drupal_path}?_format=json");
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-type: application/octet-stream',
    "Content-Disposition: file; filename=\"{$file}\""
  ]);
  curl_setopt($ch, CURLOPT_USERPWD, "{$drupal['user']}:{$drupal['pass']}");
  curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
  if (array_key_exists('verbose', $drupal) && $drupal['verbose']) {
    curl_setopt($ch, CURLOPT_VERBOSE, true);
  }
  curl_setopt($ch, CURLOPT_POSTFIELDS, $file_content);

  $result = curl_exec($ch);
  if ($result[0] !== '{') {
    print $result;
    exit(1);
  }

  $result = json_decode($result, true);

  if (!array_key_exists('fid', $result)) {
    print_r($result);
    exit(1);
  }

  return $result;
}

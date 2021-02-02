<?php
function drupal_node_get ($drupal, $id, $options = array()) {
  $ch = curl_init();

  curl_setopt($ch, CURLOPT_URL, "{$drupal['url']}/node/{$id}?_format=json");
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_USERPWD, "{$drupal['user']}:{$drupal['pass']}");
  curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
  //curl_setopt($ch, CURLOPT_VERBOSE, true);

  $result = curl_exec($ch);
  $result = json_decode($result, true);

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

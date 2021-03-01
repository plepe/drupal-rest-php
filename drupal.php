<?php
class DrupalRestAPI {
  function __construct ($options = array()) {
    $this->options = $options;

    if (!array_key_exists('curl_options', $this->options)) {
      $this->options['curl_options'] = array();
    }
    if (array_key_exists('verbose', $this->options)) {
      $this->options['curl_options'][CURLOPT_VERBOSE] = $this->options['verbose'];
    }
  }

  function loadRestExport ($path, $options = array()) {
    $ch = curl_init();
    $total = array();

    $page = 0;
    do {
      $sep = strpos($path, '?') === false ? '?' : '&';
      curl_setopt($ch, CURLOPT_URL, "{$this->options['url']}{$path}{$sep}page={$page}&_format=json");
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_USERPWD, "{$this->options['user']}:{$this->options['pass']}");
      curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
      foreach ($this->options['curl_options'] as $k => $v) {
        curl_setopt($ch, $k, $v);
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
    } while(sizeof($result) > 0);

    return $total;
  }

  function nodeGet ($id, $options = array()) {
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, "{$this->options['url']}/node/{$id}?_format=json");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "{$this->options['user']}:{$this->options['pass']}");
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    foreach ($this->options['curl_options'] as $k => $v) {
      curl_setopt($ch, $k, $v);
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
          $result[$field][$index]['data'] = $this->nodeGet($ref['target_id']);
        }
      }
    }

    return $result;
  }

  function nodeSave ($nid, $content) {
    $current_node = null;

    if ($nid) {
      $current_node = $this->nodeGet($nid);
    }

    // check for embedded fields
    foreach ($content as $field_id => $_d1) {
      foreach ($_d1 as $index => $_d2) {
        if (array_key_exists('data', $_d2)) {
          if ($current_node && array_key_exists($index, $current_node[$field_id])) {
            $current_embedded = $this->nodeGet($current_node[$field_id][$index]['target_id']);

            if (drupal_diff($_d2['data'], $current_embedded)) {
              $this->nodeSave($current_node[$field_id][$index]['target_id'], $_d2['data']);
            }

            $content[$field_id][$index] = ['target_id' => $current_node[$field_id][$index]['target_id']];
          }
          else {
            $content[$field_id][$index] = ['target_id' => $this->nodeSave(null, $_d2['data'])];
          }

        }
      }
    }

    $ch = curl_init();

    if ($nid) {
      curl_setopt($ch, CURLOPT_URL, "{$this->options['url']}/node/{$nid}?_format=json");
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    }
    else {
      curl_setopt($ch, CURLOPT_URL, "{$this->options['url']}/entity/node?_format=json");
      curl_setopt($ch, CURLOPT_POST, true);
    }

    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($content));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Content-type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_USERPWD, "{$this->options['user']}:{$this->options['pass']}");
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    if (array_key_exists('verbose', $this->options) && $this->options['verbose']) {
      print(json_encode($content, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    foreach ($this->options['curl_options'] as $k => $v) {
      curl_setopt($ch, $k, $v);
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

  function userGet ($id, $options = array()) {
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, "{$this->options['url']}/user/{$id}?_format=json");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "{$this->options['user']}:{$this->options['pass']}");
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    foreach ($this->options['curl_options'] as $k => $v) {
      curl_setopt($ch, $k, $v);
    }

    $result = curl_exec($ch);
    if ($result[0] !== '{') {
      print "Error loading '/user/{$id}': " . $result;
      exit(1);
    }

    $result = json_decode($result, true);

    if (!array_key_exists('uid', $result)) {
      print "Error loading '/user/{$id}': ";
      print_r($result);
      exit(1);
    }

    if (!$result || !sizeof($result)) {
      return null;
    }

    return $result;
  }

  function fileGet ($id, $options = array()) {
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, "{$this->options['url']}/entity/file/{$id}?_format=json");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, "{$this->options['user']}:{$this->options['pass']}");
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    foreach ($this->options['curl_options'] as $k => $v) {
      curl_setopt($ch, $k, $v);
    }

    $result = curl_exec($ch);
    if ($result[0] !== '{') {
      print "Error loading '/entity/file/{$id}': " . $result;
      exit(1);
    }

    $result = json_decode($result, true);

    if (!array_key_exists('fid', $result)) {
      print "Error loading '/entity/file/{$id}': ";
      print_r($result);
      exit(1);
    }

    if (!$result || !sizeof($result)) {
      return null;
    }

    return $result;
  }

  function userSave ($nid, $content) {
    $current_node = null;

    $ch = curl_init();

    if ($nid) {
      curl_setopt($ch, CURLOPT_URL, "{$this->options['url']}/user/{$nid}?_format=json");
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    }
    else {
      curl_setopt($ch, CURLOPT_URL, "{$this->options['url']}/entity/user?_format=json");
      curl_setopt($ch, CURLOPT_POST, true);
    }

    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($content));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Content-type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_USERPWD, "{$this->options['user']}:{$this->options['pass']}");
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    if (array_key_exists('verbose', $this->options) && $this->options['verbose']) {
      print(json_encode($content, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    foreach ($this->options['curl_options'] as $k => $v) {
      curl_setopt($ch, $k, $v);
    }

    $result = curl_exec($ch);
    if ($result[0] !== '{') {
      print $result;
      exit(1);
    }

    $result = json_decode($result, true);

    if (!array_key_exists('uid', $result)) {
      print_r($result);
      exit(1);
    }

    return $result;
  }

  function nodeRemove ($nid) {
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, "{$this->options['url']}/node/{$nid}?_format=json");
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Content-type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_USERPWD, "{$this->options['user']}:{$this->options['pass']}");
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    foreach ($this->options['curl_options'] as $k => $v) {
      curl_setopt($ch, $k, $v);
    }

    $result = curl_exec($ch);

    return true;
  }

  function paragraphSave ($id, $content) {
    $current_node = null;

    $ch = curl_init();

    if ($id) {
      curl_setopt($ch, CURLOPT_URL, "{$this->options['url']}/entity/paragraph/{$id}?_format=json");
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    }
    else {
      curl_setopt($ch, CURLOPT_URL, "{$this->options['url']}/entity/paragraph?_format=json");
      curl_setopt($ch, CURLOPT_POST, true);
    }

    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($content));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Content-type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_USERPWD, "{$this->options['user']}:{$this->options['pass']}");
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    if (array_key_exists('verbose', $this->options) && $this->options['verbose']) {
      print(json_encode($content, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    foreach ($this->options['curl_options'] as $k => $v) {
      curl_setopt($ch, $k, $v);
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

  function paragraphGet ($id) {
    $current_node = null;

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, "{$this->options['url']}/entity/paragraph/{$id}?_format=json");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Content-type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_USERPWD, "{$this->options['user']}:{$this->options['pass']}");
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    foreach ($this->options['curl_options'] as $k => $v) {
      curl_setopt($ch, $k, $v);
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

  function paragraphRemove ($id) {
    $current_node = null;

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, "{$this->options['url']}/entity/paragraph/{$id}?_format=json");
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Content-type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_USERPWD, "{$this->options['user']}:{$this->options['pass']}");
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    if (array_key_exists('verbose', $this->options) && $this->options['verbose']) {
      curl_setopt($ch, CURLOPT_VERBOSE, true);
    }

    $result = curl_exec($ch);

    return true;
  }

  /**
   * entityPath: entity_type_id/bundle/field_name
   */
  function fileUpload ($file, $entityPath) {
    $ch = curl_init();

    if (!is_array($file)) {
      $file['filename'] = $file;
    }

    if (!array_key_exists('content', $file)) {
      $file['content'] = file_get_contents($file['filename']);
    }

    curl_setopt($ch, CURLOPT_URL, "{$this->options['url']}/file/upload/{$entityPath}?_format=json");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
      'Content-type: application/octet-stream',
      "Content-Disposition: file; filename=\"{$file['filename']}\""
    ]);
    curl_setopt($ch, CURLOPT_USERPWD, "{$this->options['user']}:{$this->options['pass']}");
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    foreach ($this->options['curl_options'] as $k => $v) {
      curl_setopt($ch, $k, $v);
    }
    curl_setopt($ch, CURLOPT_POSTFIELDS, $file['content']);

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

  function get ($url) {
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, "{$this->options['url']}/user/login");
    curl_setopt($ch, CURLOPT_COOKIEFILE, ""); // use cookies, but don't save them
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    foreach ($this->options['curl_options'] as $k => $v) {
      curl_setopt($ch, $k, $v);
    }

    // This array will hold the field names and values.
    $postdata=array(
      "name"=>$this->options['email'],
      "pass"=>$this->options['pass'],
      "form_id"=>"user_login_form",
      "op"=>"Log in"
    );
    // Tell curl we're going to send $postdata as the POST data
    curl_setopt ($ch, CURLOPT_POSTFIELDS, http_build_query($postdata));

    $result=curl_exec($ch);
    $headers = curl_getinfo($ch);

    if ($headers['url'] == $this->options['url']) {
        die("Cannot login.");
    }

    curl_setopt($ch, CURLOPT_URL, "{$this->options['url']}{$url}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    return curl_exec($ch);
  }
}

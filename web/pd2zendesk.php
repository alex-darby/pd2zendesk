<?php
/*
  TODO::
    -impliment the annotate case.


*/
$messages = json_decode(file_get_contents("php://input"));

$zd_subdomain = getenv('ZENDESK_SUBDOMAIN');
$zd_username = getenv('ZENDESK_USERNAME');
$zd_api_token = getenv('ZENDESK_API_TOKEN');
$pd_api_token = getenv('PAGERDUTY_API_TOKEN');
$pd_from_email = getenv('PAGERDUTY_USER_EMAIL');

/*
$zd_subdomain = "w2globaldatahelp";
$zd_username = "alex.darby@w2globaldata.com";
$zd_api_token = "92vBlkVMfsAsZbPJQaeRfAma3Ba5GZm0wOGZYezi";
$pd_api_token = "ReaGHVfJN-T_qtLiCpP-";
$pd_from_email = "alexjdarby@gmail.com";
*/
if ($messages) foreach ($messages->messages as $webhook) {
  $webhook_type = $webhook->event;
/*
  echo "\$webhook_type = ";
  var_dump($webhook_type);
*/
  $incident_id = $webhook->incident->incident_number;
/*
  echo "\$incident_id = ";
  var_dump($incident_id);
*/

  // ticket_id is the Zendesk ticket number
  $ticket_id = $webhook->incident->incident_key;
  $ticket_id = trim($ticket_id,'#');
/*
  echo "\$ticket_id = ";
  var_dump($ticket_id);
*/
  $ticket_url = $webhook->incident->html_url;
/*
  echo "\$ticket_url = ";
  var_dump($ticket_url);
*/
  $pd_requester_id = $webhook->incident->assignments[0]->assignee->summary;
/*
  echo "\$pd_requester_id = ";
  var_dump($pd_requester_id);
*/
  switch ($webhook_type) {
    case "incident.trigger":
      $verb = "triggered";
      /*

      
      // Removed as the JSON object structure has changed and assignees are not processed in this way.

      $assigned_array = $webhook->incident->assignments;
      $assigned_users = array();
      foreach ($assigned_array as $assigned_user) {
        array_push($assigned_users, $assigned_user->object->name);
      }
      */
      $action_message = " and is assigned to " . $pd_requester_id;
      //Remove the pd_integration tag in Zendesk to eliminate further updates
      $url = "https://$zd_subdomain.zendesk.com/api/v2/tickets/$ticket_id/tags.json";
      // $url = "http://httpresponder.com/sakjmdf9ef93";
      $data = array('tags'=>array('pd_integration'));
      $data_json = json_encode($data);
      $status_code = http_request($url, $data_json, "DELETE", "basic", $zd_username, $zd_api_token);
      break;
    case "incident.acknowledge":
      $verb = "acknowledged ";

      /*

      // Removed as the JSON object structure has changed and acknowledgers are not processed in this way. 

      $acknowledger_array = $webhook->data->incident->acknowledgers;
      $acknowledgers = array();
      foreach ($acknowledger_array as $acknowledger) {
        array_push($acknowledgers, $acknowledger->object->name);
      }
      */
      $acknowledger = $webhook->incident->acknowledgements[0]->acknowledger->summary;
      $action_message = " by " . $acknowledger;
      break;
    /*  
    case "incident.annotate":
      $verb = "annotated ";

      $acknowledger = $webhook->incident->acknowledgements[0]->acknowledger->summary;
      $action_message = " by " . $acknowledger;
      break;
    */
    case "incident.resolve":
      $verb = "resolved";
      $action_message = " by " . $pd_requester_id;
      break;
    default:
      continue 2;
  }
  //Update the Zendesk ticket when the incident is acknowledged or resolved.
  $url = "https://$zd_subdomain.zendesk.com/api/v2/tickets/$ticket_id.json";
  //$url = "http://mockbin.org/bin/be3f6d18-6693-45c3-9b5e-a516abc9022e";
  $data = array('ticket'=>array('comment'=>array('public'=>'false','body'=>"This ticket has been $verb" . $action_message . " in PagerDuty.  To view the incident, go to $ticket_url.")));
  $data_json = json_encode($data);
  $status_code = http_request($url, $data_json, "PUT", "basic", $zd_username, $zd_api_token);
  if ($status_code != "200" && $verb != "resolved") {
    //If we did not POST correctly to Zendesk, we'll add a note to the ticket, as long as it was a triggered or acknowledged ticket.
    $url = "https//api.pagerduty.com/incidents/$incident_id/notes";
    $data = array('note'=>array('content'=>'The Zendesk ticket was not updated properly.  Please try again.'));
    $data_json = json_encode($data);
    http_request($url, $data_json, "POST", "token", $pd_from_email, $pd_api_token);
  }
}
function http_request($url, $data_json, $method, $auth_type, $username, $token) {

  // var_dump($url, $data_json, $method, $auth_type, $username, $token);

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  if ($auth_type == "token") {
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Accept: application/vnd.pagerduty+json;version=2',
        'Content-Type: application/json',
        'Content-Length: ' . strlen($data_json),
        "Authorization: Token token=$token",
        "From: $username"
    ));
  }
  else if ($auth_type == "basic") {
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-Length: ' . strlen($data_json)));
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, "$username/token:$token");
  }
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  curl_setopt($ch, CURLOPT_POSTFIELDS,$data_json);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  $response  = curl_exec($ch);
  $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

  curl_close($ch);
  return $status_code;
}

?>

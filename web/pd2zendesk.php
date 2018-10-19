<?php
$messages = json_decode(file_get_contents("php://input"));
$zd_subdomain = getenv('ZENDESK_SUBDOMAIN');
$zd_username = getenv('ZENDESK_USERNAME');
$zd_api_token = getenv('ZENDESK_API_TOKEN');
$pd_api_token = getenv('PAGERDUTY_API_TOKEN');
$pd_from_email = getenv('PAGERDUTY_USER_EMAIL');
if ($messages) foreach ($messages->messages as $webhook) {
  $webhook_type = $webhook->type;
  $incident_id = $webhook->data->incident->id;
  $incident_number = $webhook->data->incident->incident_number;
  $ticket_id = $webhook->data->incident->trigger_summary_data->extracted_fields->ticket_id;
  $ticket_url = $webhook->data->incident->html_url;
  $pd_requester_id = $webhook->data->incident->assigned_to_user->id;
  switch ($webhook_type) {
    case "incident.trigger":
      $verb = "triggered";
      $assigned_array = $webhook->data->incident->assigned_to;
      $assigned_users = array();
      foreach ($assigned_array as $assigned_user) {
        array_push($assigned_users, $assigned_user->object->name);
      }
      $action_message = " and is assigned to " . implode(", ", $assigned_users);
      //Remove the pd_integration tag in Zendesk to eliminate further updates
      $url = "https://$zd_subdomain.zendesk.com/api/v2/tickets/$ticket_id/tags.json";
      $data = array('tags'=>array('pd_integration'));
      $incident_tag = array('tags'=>array('pd_' . $incident_number));
      $data_json = json_encode($data);
      $incident_tag_json = json_encode($data);
      $status_code = http_request($url, $data_json, "DELETE", "basic", $zd_username, $zd_api_token);
      http_request($url, $incident_tag_json, "POST", "basic", $zd_username, $zd_api_token);
      break;
    case "incident.acknowledge":
      $verb = "acknowledged ";
      $acknowledger_array = $webhook->data->incident->acknowledgers;
      $acknowledgers = array();
      foreach ($acknowledger_array as $acknowledger) {
        array_push($acknowledgers, $acknowledger->object->name);
      }
      $action_message = " by " . implode(", ", $acknowledgers);
      break;
    case "incident.resolve":
      $verb = "resolved";
      $action_message = " by " . $webhook->data->incident->resolved_by_user->name;
      break;
    default:
      continue 2;
  }
  //Update the Zendesk ticket when the incident is acknowledged or resolved.
  $url = "https://$zd_subdomain.zendesk.com/api/v2/tickets/$ticket_id.json";
  $data = array('ticket'=>array('comment'=>array('public'=>'false','body'=>"This ticket has been $verb" . $action_message . " in PagerDuty, incident number (" . $incident_number . ").  To view the incident, go to $ticket_url.")));
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
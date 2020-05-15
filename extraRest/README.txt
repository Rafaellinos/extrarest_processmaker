The extraRest plugin includes extra REST endpoints to be used in ProcessMaker.
It has been tested in ProcessMaker 3.2.0 and 3.2.1.

To install, download the extraRest-X.tar file. Then login to ProcessMaker
as a user who has the PM_SETUP permission in their role (such as the "admin" user).
Go to Admin > Plugins > Plugin Manager and click on "Import". 
After importing the extraRest-X.tar file, then enable the plugin.


The following extra REST endpoints are included in this plugin:

I haven't had time to document them all. See the contents of:
extraRest/extraRest/src/Services/Api/ExtraRest/extra.php



Claim a case for a user where a task is unassigned because the task 
is Self Service or Self Service Value Based Assignment. 

POST http://{domain-or-ip}/api/1.0/workflow/extrarest/case/{app_uid}/claim

URL parameters:
  app_uid:  Unique ID of case to claim.

POST parameters:
  del_index: Optional. The delegation index of the task to claim. Only needs 
             to be included if there are multiple open tasks in the case.
  usr_uid:   Optional. Unique ID of the user to assign to case. Only include 
             if the logged-in user is a process supervisor assigning another user.
  
Response:
  None if successful. HTTP status code is 200.

Example 1:
Assign the logged-in user to a Self Service Task where there is only one open task in case:
-------------
$caseId = '2554682895ac25995666e24055342045';
$url = "/api/1.0/workflow/extrarest/case/$caseId/claim";
$aVars = array();
$oRet = pmRestRequest("POST", $url, $aVars, $oToken->access_token);
-------------

Example 2:
Assign the logged-in user to a Self Service Task where there are 2 open tasks in case:
-------------
$caseId = '2554682895ac25995666e24055342045';
$url = "/api/1.0/workflow/extrarest/case/$caseId/claim";
$aVars = array(
   'del_index' => 3
);
$oRet = pmRestRequest("POST", $url, $aVars, $oToken->access_token);
-------------

Example 3:
Assign another user to Self Service Task when the logged-in user is a Process Supervisor:
-------------
$caseId = '2554682895ac25995666e24055342045';
$url = "/api/1.0/workflow/extrarest/case/$caseId/claim";
$aVars = array(
  'del_index' => 2,  
  'usr_uid'   => '10654575559caec5e953104064429578' //unique ID of user to assign to task
);
$oRet = pmRestRequest("POST", $url, $aVars, $oToken->access_token); 
--------------  
  


For participated cases:
http://{domain}/api/1.0/{workspace}/extrarest/cases/user/{usr_uid}?action=sent

For unassigned cases:
http://{domain}/api/1.0/{workspace}/extrarest/cases/user/{usr_uid}?action=unassigned

   

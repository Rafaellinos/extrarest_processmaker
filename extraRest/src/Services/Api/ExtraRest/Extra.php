<?php
namespace Services\Api\extraRest;
use \ProcessMaker\Services\Api;
use \Luracast\Restler\RestException;
use \G;
use \UsersPeer;
use \CasesPeer;
use \ProcessMaker\Project\Adapter;
use \ProcessMaker\BusinessModel\Validator;
use \ProcessMaker\Util\DateTime;



class Extra extends Api
{
    private $_keys;
    private $_values;
    
    function add($key, $value) {
       $this->_keys[] = $key;
       $this->_values[] = $value;      
    }
    
    function __construct() {
        //add code here to be executed automatically every time the class is instantiated.        
        
        //print "PATH_HOME:".PATH_HOME."<br>\nPATH_TRUNK:".PATH_TRUNK."<br>\nPATH_OUTTRUNK:".PATH_OUTTRUNK;
        require_once(PATH_TRUNK.'gulliver/system/class.rbac.php');
    }
           
    
    /**
     * Return case information (without task information), ignoring security restrictions
     * 
     * @url GET /case/:app_uid
     * 
     * @access protected
     *      
     * @param string $app_uid {@min 32}{@max 32}
     * @return array
     * 
     * @author Amos Batto <amos@processmaker.com>
     * @copyright Public Domain
     */
    public function getCaseInfo($app_uid)
    {   
        try {   
            $g = new G();
            $g->loadClass("cases");
            $oCase = new \Cases();
            
            //lookup the last delegation index in the case
            $oCriteria = new \Criteria();
            $oCriteria->add(\AppDelegationPeer::APP_UID, $app_uid);
            $oCriteria->addDescendingOrderByColumn(\AppDelegationPeer::DEL_INDEX);
            $oApplication = \AppDelegationPeer::doSelectOne($oCriteria);
            //return $oCriteria;
            
            if (is_null($oApplication)) {
                throw new \Exception("Invalid case ID '$app_uid'.");
            }
            
            $delIndex = $oApplication->getDelIndex();
            $aCaseInfo = $oCase->loadCase($app_uid, $delIndex);
            $oDelay = new \AppDelay();
            
            if ($oDelay->isPaused($app_uid, $aCaseInfo["DEL_INDEX"])) {
                $aCaseInfo['APP_STATUS'] = 'PAUSED';
            }
            
            return $aCaseInfo;
        } 
        catch (\Exception $e) {
            throw (new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage()));
        }
    }
    
    /**
     * Return case and task information, ignoring security restrictions.
     * 
     * @url GET /case/:app_uid/:del_index
     * @access protected
     * 
     * @param string $app_uid {@min 32}{@max 32}
     * @param int $del_index 
     * @return array
     * 
     * @author Amos Batto <amos@processmaker.com>
     * @copyright Public Domain
     */
    public function getCaseInfoWithIndex($app_uid, $del_index)
    {  
        try {
            $g = new G();
            $g->loadClass("cases");
            $oCase = new \Cases();
            $aCaseInfo = $oCase->loadCase($app_uid, $del_index);
            
            if (!is_array($aCaseInfo) or !isset($aCaseInfo['APP_UID'])) {
                throw new \Exception("Invalid app_uid or del_index");
            } 
            
            $oDelay = new \AppDelay();
            
            if ($oDelay->isPaused($app_uid, $del_index)) {
                $aCaseInfo['APP_STATUS'] = 'PAUSED';
            }
            
            return $aCaseInfo;
        } 
        catch (\Exception $e) {
            throw (new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage()));
        }
    }
    
    /**
     * Change the case's status from TO_DO to DRAFT or from DRAFT to TO_DO.
     * Logged-in user must either be the assigned user to specified delegation index or 
     * a process supervisor for the case's process. 
     * 
     * @url PUT /case/status/:app_uid
     * @access protected
     *
     * @param string $app_uid   Case ID. {@min 32}{@max 32} {@from path}
     * @param string $status    Case status to set. {@from body} {@choice TO_DO,DRAFT}
     * @param int    $del_index Optional delegation index. If not set, then the current delegation in case. {@from body}
     * 
     * @author Amos Batto <amos@processmaker.com>
     * @copyright Public Domain
     */
    public function putCaseStatus($app_uid, $status, $del_index=null) {  
        try {
            $g = new G();
            $g->loadClass("cases");
            $oCase = new \Cases();
            
            if (isset($del_index)) {
                $aCaseInfo = $oCase->loadCaseByDelegation($app_uid, $del_index);
            } else {
                $aCaseInfo = $oCase->loadCaseInCurrentDelegation($app_uid);
                $del_index = $aCaseInfo["DEL_INDEX"];
                $aCaseInfo = $oCase->loadCaseByDelegation($app_uid, $del_index);
            }
            
            if (!empty($aCaseInfo['DEL_FINISH_DATE'])) {
                throw new \Exception("Cannot change status because delegation $del_index is closed in case '$app_uid'.");
            }
            
            if ($aCaseInfo['USR_UID'] != $this->getUserId()) {
                if ($this->userCanAccess('PM_SUPERVISOR') == 0) {
                    throw new \Exception("Logged-in user needs the PM_SUPERVISOR permission in role.");
                }
            }
            
            if ($aCaseInfo['APP_STATUS'] != 'TO_DO' and $aCaseInfo['APP_STATUS'] != 'DRAFT') {
                throw new \Exception("Case has status '{$aCaseInfo['APP_STATUS']}', so its status can't be changed.");
            }
            
            $aCaseInfo['APP_STATUS'] = $status;
            $oCase->updateCase($aCaseInfo);  
        } 
        catch (\Exception $e) {
            throw (new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage()));
        }
        
        return null;
    }
    
    
    /**
     * Return the list of cases found under Home > Review for Process Supervisors.
     * 
     * @url GET /cases/review
     * @access protected
     * 
     * @return array
     * 
     * @author Amos Batto <amos@processmaker.com>
     * @copyright Public Domain
     */
    public function getCaseListForReview() {
        try {  
            $g = new G();
            $g->LoadClass("applications");
            
            if ($this->userCanAccess('PM_SUPERVISOR') == 0) {
                throw new \Exception("Logged-in user lacks the PM_SUPERVISOR permission in role.");
            }

            /*In /workflow/engine/classes/class.applications.php
            Applications::getAll(
                $userUid,
                $start = null,
                $limit = null,
                $action = null,
                $filter = null,
                $search = null,
                $process = null,
                $status = null,
                $type = null,
                $dateFrom = null,
                $dateTo = null,
                $callback = null,
                $dir = null,
                $sort = "APP_CACHE_VIEW.APP_NUMBER",
                $category = null,
                $configuration = true,
                $paged = true,
                $newerThan = '',
                $oldestThan = ''
            )*/
            $userId = $this->getUserId(); 
            
            $oApp = new \Applications();
            $aList = $oApp->getAll($userId, null, null, 'to_revise');
            return $aList['data'];
        } 
        catch (\Exception $e) {
            throw new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage());
        }   
    }
    
    /**
     * Get information about the logged-in user
     * 
     * @url GET /login-user
     * @access protected
     *      
     * @return string
     * 
     * @author Amos Batto <amos@processmaker.com>
     * @copyright Public Domain
     */
    public function getLoginUser()
    {   
        try {
            $g = new G();
            $g->loadClass("pmFunctions");
            $userId = $this->getUserId();
            $aInfo = array('uid' => $userId);
            $aInfo = array_merge($aInfo, PMFInformationUser($userId));
            return $aInfo;
        } 
        catch (\Exception $e) {
            throw new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage());
        }
    }  
    
    /**
     * Set the login language in $_SESSION['SYS_LANG']
     * 
     * @url PUT /language/:lang
     * @access protected
     *
     * @param string $lang 
     * 
     * @author Amos Batto <amos@processmaker.com>
     * @copyright Public Domain
     */
    public function putSystemLanguage($lang)
    {  
        try {
            if (empty($lang) or trim($lang) == false) {
                throw new \Exception("System language cannot be empty.");
            }
        
            $_SESSION['SYS_LANG'] = $lang;
            return null;
        } 
        catch (\Exception $e) {
            throw new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage());
        }
    } 
    
    /**
     * Get the login language stored in $_SESSION['SYS_LANG']
     * 
     * @url GET /language
     * @access protected 
     * 
     * @author Amos Batto <amos@processmaker.com>
     * @copyright Public Domain
     */
    public function getSystemLanguage($lang)
    {  
        try {
            if (!isset($_SESSION['SYS_LANG'])) {
                throw new \Exception("System language for login session does not exist.");
            }
                
            return $_SESSION['SYS_LANG'];
        } 
        catch (\Exception $e) {
            throw new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage());
        }
    }
    
    /**
     * Return information about a case based on its case number 
     * and an optional delegation index number. 
     * Ex: /api/1.0/workflow/extrarest/case/number/221?index=3
     * 
     * @url GET /case/number/:app_number
     * @access protected
     * 
     * @param int $app_number
     * @param int $index {@from path}
     * @return array
     * 
     * @author Amos Batto <amos@processmaker.com>
     * @copyright Public Domain
     */
    public function getCaseFromNumber($app_number, $index=null)
    {  
        try {
            if ($this->userCanAccess('PM_CASES') == 0) {
                throw new \Exception("Logged-in user lacks the PM_CASES permission in role.");
            }
            
            $g = new \G();
            $g->loadClass("pmFunctions");
            $g->loadClass("cases");
            $oCase = new \Cases();
        
            $sql = "SELECT * FROM APPLICATION WHERE APP_NUMBER=" . $app_number;
            $aReturn = executeQuery($sql);
            
            if (!is_array($aReturn) or empty($aReturn)) {
                throw new \Exception("Unable to find case number '$app_number'.");
            }
            
            if ($index === null) {
               $aCaseInfo = $oCase->loadCase($aReturn[1]['APP_UID']);
            } else {
               $aCaseInfo = $oCase->loadCase($aReturn[1]['APP_UID'], $index);
            }
            
            if (!is_array($aCaseInfo) or !isset($aCaseInfo['APP_UID'])) {
                throw new \Exception("Invalid app_uid or del_index");
            } 
        } 
        catch (\Exception $e) {
            throw (new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage()));
        }
        
        return $aCaseInfo;
    } 
    
    /**
     * Return the list of cases for a specified user without being that logged-in user
     * 
     * @url GET /cases/user/:user_uid
     * @access protected
     * 
     * @param string $user_uid User's unique ID. Set to 00000000000000000000000000000000 for logged-in user. {@from path} {@min 32} {@max 32}
     * @param int $start {@from query}
     * @param int $limit {@from query}
     * @param string $action {@from query} {@choice todo,draft,sent,unassigned,paused,completed,cancelled,search,simple_search,to_revise,to_reassign,all,gral,default} 
     * @param string $filter {@from query} {@choice read,unread,started,completed}
     * @param string $search String to search for. {@from query}
     * @param string $pro_uid Process unique ID. {@from query} {@min 32}{@max 32}
     * @param string $app_status Only used if action=search {@from query} {@choice TO_DO,DRAFT,PAUSED,CANCELLED,COMPLETED,ALL}
     * @param string $date_from YYYY-MM-DD {@from query}
     * @param string $date_to YYYY-MM-DD {@from query}
     * @param string $dir Ascending or descending sort order. {@from query} {@choice ASC,DESC}
     * @param string $sort Database field to sort by. {@from query}
     * @param string $cat_uid Category unique ID. {@from query} {@min 32}{@max 32}
     * @param boolean $configuration Set to 1 or 0. {@from query} {@from path}
     * @param boolean $paged Set to 1 or 0. {@from query} {@from path}
     * @param string $newer_than YYYY-MM-DD. Like date_from, but > not >=. {@from query}
     * @param string $older_than YYYY-MM-DD. Like date_to, but < not <=. {@from query}
     * 
     * @return array
     * 
     * @author Amos Batto <amos@processmaker.com>
     * @copyright Public Domain
     */
    public function getCasesForUser(
        $user_uid,                            //for the logged-in user, set to: 00000000000000000000000000000000 
        $start = null,
        $limit = null,
        $action = null, 
        $filter = null,
        $search = null,
        $pro_uid = null,                      //process unique ID
        $app_status = null,                   //only used if $action=='search'
        $date_from = null,
        $date_to = null,
        $dir = null,                          //ASC or DESC
        $sort = "APP_CACHE_VIEW.APP_NUMBER",
        $cat_uid = null,                      //category unique ID
        $configuration = true,                
        $paged = true,
        $newer_than = '',                     //same as $date_from, but > rather than >=
        $older_than = ''                      //same as $date_to, but < rather than <=
    ) {
        try {  
            $type = null;                         //not setable parameter
            $callback = null;                     //not setable parameter
            
            $g = new G();
            $g->LoadClass("applications");

            if ($user_uid == 0) {
                $user_uid = $this->getUserId();
            }
            
            if ($this->userCanAccess('PM_CASES') == 0) {
                throw new \Exception("Logged-in user needs the PM_CASES permission in role.");
            }
            
            if ($user_uid != $this->getUserId() and $this->userCanAccess('PM_ALLCASES') == 0) {
                throw new \Exception("Logged-in user needs the PM_ALLCASES permission to access another user's cases.");
            } 
            
            //see Applications::getAll() defined in workflow/engine/classes/class.applications.php
            $oApp = new \Applications();
            $aList = $oApp->getAll(
                $user_uid, 
                $start,
                $limit,
                $action,
                $filter,
                $search,
                $pro_uid,
                $app_status,
                $type,
                $date_from,
                $date_to,
                $callback,
                $dir,
                $sort,
                $cat_uid,
                $configuration,
                $paged,
                $newer_than,
                $older_than
            );
            if ($paged) {
                return $aList;
            } else {
                return $aList['data'];
            }
        } 
        catch (\Exception $e) {
            throw new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage());
        }   
    }
    
    /**
     * Execute an SQL SELECT query in the current workspace's workflow database. 
     * By default, the initial workspace is named "wf_workflow". The results
     * are returned in a numbered array starting from 1, just like executeQuery().
     * 
     * Note 1: For security reasons, this endpoint is commented out. If
     * you want to test it, then remove the comments and change the [AT] to @
     * It is strongly recommended to adapt this code to include the specific
     * SQL query that you need and only pass the specific parameters that
     * need to be changed to the endpoint. For security reasons, do not 
     * allow this endpoint to execute any SQL query. Its code is provided
     * to show you how to execute SQL queries in ProcessMaker, but it needs 
     * to be adapted for your specific purpose to make it safer. 
     * 
     * Note 2: Only SELECT statements in the current workspace's workflow
     * database are allowed. If thinking of modifying this endpoint to allow UPDATE, INSERT and DELETE
     * statements, then make sure to change the ProcessMaker configuration files. See:
     * http://wiki.processmaker.com/3.0/Consulting_the_ProcessMaker_databases#Protecting_PM_Core_Tables
     * 
     * 
     * [AT]url POST /sql
     * [AT]access protected
     * 
     * [AT]param string $sql SQL SELECT statement to execute. {@from body}
     *   
     * [AT]return array
     * 
     * [AT]author Amos Batto <amos@processmaker.com>
     * [AT]copyright Public Domain
     */
    /* 
    public function postSql($sql) {
        try {
            $g = new \G();
            $g->loadClass("pmFunctions");
            
            if (preg_match('/^\s*select\s/i', $sql) == 0) {
                throw new \Exception("SQL must be a SELECT statement.");
            } 

            $aResult = executeQuery($sql);
            
            $aRows = array();
            foreach ($aResult as $aRow) {
                $aRows[] = $aRow;
            }
            return $aRows;
        } 
        catch (\Exception $e) {
            throw new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage());
        }  
    }
    */
    
    /**
     * Get a list of all the case reassignments in the workspace. 
     * The logged-in user needs the PM_ALLCASES permission in his/her role.
     * 
     * @url GET /cases/all/reassignments
     * @access protected
     *   
     * @return array
     * 
     * @author Amos Batto <amos@processmaker.com>
     * @copyright Public Domain
     */
    public function getAllCaseReassignments() {
        try {
            if ($this->userCanAccess('PM_ALLCASES') == 0) {
                throw new \Exception("Logged-in user lacks the PM_ALLCASES permission in role.");
            }
            
            $g = new \G();
            $g->loadClass("pmFunctions");
            
            $sql = "SELECT ACV.APP_UID AS CASE_ID, 
                ACV.APP_NUMBER AS CASE_NUMBER, 
                ACV.APP_TITLE AS CASE_TITLE, 
                ACV.PRO_UID AS PROCESS_ID, 
                ACV.APP_PRO_TITLE AS PROCESS_TITLE,  
                ACV.TAS_UID AS TASK_ID, 
                ACV.APP_TAS_TITLE AS TASK_TITLE, 
                ACV.PREVIOUS_USR_UID AS PREVIOUS_USER_ID,
                ACV.APP_DEL_PREVIOUS_USER AS PREVIOUS_USER, 
                ACV.USR_UID AS REASSIGNED_USER_ID, 
                ACV.APP_CURRENT_USER AS REASSIGNED_USER,
                AD.APP_ENABLE_ACTION_USER AS REASSIGNER_ID,
                CONCAT(U.USR_LASTNAME, ' ', U.USR_FIRSTNAME) AS REASSIGNER, 
                AD.APP_ENABLE_ACTION_DATE AS REASSIGN_DATE, 
                AN.NOTE_CONTENT AS REASSIGN_REASON
                ACV.DEL_TASK_DUE_DATE AS TASK_DUE_DATE
                FROM APP_DELAY AD
                LEFT JOIN USERS U ON AD.APP_ENABLE_ACTION_USER=U.USR_UID
                LEFT JOIN APP_DELEGATION DLG ON AD.APP_UID=DLG.APP_UID AND 
                    AD.APP_DEL_INDEX=DLG.DEL_INDEX
                LEFT JOIN APP_CACHE_VIEW ACV ON DLG.APP_UID=ACV.APP_UID AND 
                    DLG.TAS_UID=ACV.TAS_UID AND 
                    DLG.DEL_INDEX < ACV.DEL_INDEX
                LEFT JOIN APP_NOTES AN ON AD.APP_UID=AN.APP_UID AND 
                    AD.APP_ENABLE_ACTION_USER=AN.USR_UID AND
                    AD.APP_ENABLE_ACTION_DATE=AN.NOTE_DATE
                WHERE AD.APP_TYPE='REASSIGN'
                ORDER BY AD.APP_ENABLE_ACTION_DATE DESC";
        
            $aResult = executeQuery($sql);
            
            $aRows = array();
            foreach ($aResult as $aRow) {
                $aRows[] = $aRow;
            }
            return $aRows;
        } 
        catch (\Exception $e) {
            throw new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage());
        }  
    }
    
    /**
     * Get a list of all the open cases (including paused cases) in the workspace. 
     * The logged-in user needs the PM_ALLCASES permission in his/her role.
     * 
     * @url GET /cases/all/open
     * @access protected
     *   
     * @return array
     * 
     * @author Amos Batto <amos@processmaker.com>
     * @copyright Public Domain
     */
    public function getAllOpenCases() {
        try {
            if ($this->userCanAccess('PM_ALLCASES') == 0) {
                throw new \Exception("Logged-in user lacks the PM_ALLCASES permission in role.");
            }
            
            $g = new \G();
            $g->loadClass("pmFunctions");
            
            $sql = "SELECT ACV.APP_UID AS CASE_ID, 
                ACV.APP_NUMBER AS CASE_NUMBER, 
                ACV.APP_TITLE AS CASE_TITLE,
                ACV.PRO_UID AS PROCESS_ID, 
                ACV.APP_PRO_TITLE AS PROCESS_TITLE,
                ACV.TAS_UID AS TASK_ID, 
                ACV.APP_TAS_TITLE AS TASK_TITLE, 
                ACV.APP_STATUS AS CASE_STATUS, 
                ACV.DEL_TASK_DUE_DATE AS TASK_DUE_DATE,
                ACV.DEL_PRIORITY AS TASK_PRIORITY,
                ACV.APP_UPDATE_DATE AS LAST_UPDATE_DATE,
                AD.APP_TYPE AS TYPE_ACTION,
                FROM APP_CACHE_VIEW ACV
                LEFT JOIN APP_DELAY AD ON ACV.APP_UID=AD.APP_UID AND ACV.DEL_INDEX=AD.APP_DEL_INDEX
                WHERE (ACV.DEL_THREAD_STATUS='OPEN' AND ACV.DEL_FINISH_DATE IS NULL AND
                ACV.APP_STATUS <> 'COMPLETED' AND ACV.APP_STATUS <> 'CANCELLED') OR
                (ACV.APP_THREAD_STATUS='OPEN' AND AD.APP_TYPE='PAUSE' AND 
                APP_AUTOMATIC_DISABLED_DATE IS NULL AND APP_DISABLE_ACTION_USER='0')
                ORDER BY ACV.APP_NUMBER";
        
            $aResult = executeQuery($sql);
            
            $aRows = array();
            foreach ($aResult as $aRow) {
                $aRows[] = $aRow;
            }
            return $aRows;
        } 
        catch (\Exception $e) {
            throw new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage());
        }  
    }
    

    /**
     * Function to replicate RBAC::userCanAccess()
    Note: Had to create this function to query DB, because can't use this header:
    [AT]class  AccessControl {@permission PM_USERS}
    because it redirects to http://{domain-or-ip}/loginForm.php 
    
    //This returns an empty array:
    $oPerm = new \ProcessMaker\Services\Api\Role\Permission();
    $oPerm->doGetPermissions($roleId);
    
    //and this doesn't work, because there is no global $RBAC:
    global $RBAC;
    if ($RBAC->userCanAccess('PM_USERS') == 1) {...}
    
    //and this causes an error:
    $rbac = \RBAC::getSingleton();
    $rbac->load($loggedUserId);
    
    //And this doesn't work either:   
    global $RBAC;
    if (!isset($RBAC)) {
        \Bootstrap::LoadSystem('rbac');
        $RBAC = \RBAC::getSingleton();
        $RBAC->sSystem = 'PROCESSMAKER';
        $RBAC->initRBAC();
        $RBAC->loadUserRolePermission($RBAC->sSystem, $userId);
    }
    $RBAC->userCanAccess('PM_SUPERVISOR');
        
    //this also doesn't work:    
    $r = new \RBAC();
    $aPerms = $r->loadUserRolePermission('PROCESSMAKER', $userId);
    $permissions = Util::nestedValue($userId, 'aUserInfo', 'PROCESSMAKER', 'PERMISSIONS');
     */
    public function userCanAccess($permissionCode, $userId='') {
        if (empty($userId)) {
            $userId = $this->getUserId();
        }
        
        $oUser = new \RbacUsers();
        $aRole = $oUser->getUserRole($userId);
        $roleId = $aRole['ROL_UID'];
        
        $g = new \G();
        $g->loadClass('pmFunctions');
        
        $sql = "SELECT P.* FROM RBAC_ROLES_PERMISSIONS RP, RBAC_PERMISSIONS P 
            WHERE P.PER_CODE='$permissionCode' AND P.PER_UID=RP.PER_UID AND RP.ROL_UID='$roleId'";
        $aPerm = executeQuery($sql);
        
        return count($aPerm);
    }
    
    /**
     * Update user configuration stored in a serialized array in CONFIGURATION.CFG_VALUE. 
     * 
     * @url PUT /user/:usr_uid/config
     * @access protected
     * 
     * @param string $usr_uid User's unique ID. {@from path}{@min 32}{@max 32}
     * @param string $default_lang Default interface language in 'xx' or 'xx-CC' format. 
     *    {@from body} {@pattern /^[a-z]{2,3}([\-_][A-Z]{2})?$/}
     * @param string $default_menu Default main menu: '' (default for role), PM_CASES=Home, PM_FACTORY=Designer, PM_SETUP=Admin 
     *    {@from body} {@choice PM_CASES,PM_FACTORY,PM_SETUP,PM_DASHBOARD,} 
     * @param string $default_cases_menu Default Cases submenu: ''=default Inbox, CASES_SENT=participated, CASES_SELFSERVICE=unassigned, CASES_SEARCH=advanced search, CASES_TO_REVISE=review, CASES_FOLDERS=Documents 
     *    {@from body} {@choice CASES_START_CASE,CASES_INBOX,CASES_DRAFT,CASES_SENT,CASES_SELFSERVICE,CASES_PAUSED,CASES_SEARCH,CASES_TO_REVISE,CASES_TO_REASSIGN,CASES_FOLDERS,} 
     * 
     * @return array An array holding the updated user configuration.
     * 
     * @author Amos Batto <amos@processmaker.com>
     * @copyright Public Domain
     */
    public function putUserConfig($usr_uid, $default_lang=null, $default_menu=null, $default_cases_menu=null)
    {  
        try {
            $rbac = \RBAC::getSingleton();
            $rbac->initRBAC();
            
            if ($rbac->verifyUserId($usr_uid) != 1) {
                throw new \Exception("User with ID '$usr_uid' does not exist.");
            }
            
            if ($this->userCanAccess('PM_USERS') == 0) {
                throw new \Exception("Logged-in user lacks the PM_USERS permission in role.");
            }
            
            $g = new \G();
            $g->loadClass('configuration');
            $oConf = new \Configurations();
            $oConf->loadConfig($x, 'USER_PREFERENCES', '', '', $usr_uid, '' );
            
            //set user configuration:
            if (isset($default_lang)) {
                $oConf->aConfig['DEFAULT_LANG'] = $default_lang;
            }
            if (isset($default_menu)) {
                $oConf->aConfig['DEFAULT_MENU'] = $default_menu;
            }
            if (isset($default_cases_menu)) {
                $oConf->aConfig['DEFAULT_CASES_MENU'] = $default_cases_menu;
            }
            
            //update configuration:
            $oConf->saveConfig('USER_PREFERENCES', '', '', $usr_uid);
            
            $oConf->loadConfig($y, 'USER_PREFERENCES', '', '', $usr_uid, '');
            return $oConf->aConfig;
        } 
        catch (\Exception $e) {
            throw new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage());
        }
    } 
    
    /**
     * Get user configuration stored in a serialized array in CONFIGURATION.CFG_VALUE. 
     * 
     * @url GET /user/:usr_uid/config
     * @access protected
     * 
     * @param string $usr_uid User's unique ID. {@from path}{@min 32}{@max 32}
     * 
     * @return array An array holding the user configuration.
     * 
     * @author Amos Batto <amos@processmaker.com>
     * @copyright Public Domain
     */
    public function getUserConfig($usr_uid) {  
        try {
            $rbac = \RBAC::getSingleton();
            $rbac->initRBAC();
            
            if ($rbac->verifyUserId($usr_uid) != 1) {
                throw new \Exception("User with ID '$usr_uid' does not exist.");
            }
            
            if ($this->userCanAccess('PM_USERS') == 0) {
                throw new \Exception("Logged-in user lacks the PM_USERS permission in role.");
            }
            
            $g = new \G();
            $g->loadClass('configuration');
            $oConf = new \Configurations();
            $oConf->loadConfig($x, 'USER_PREFERENCES', '', '', $usr_uid, '' );
            
            return $oConf->aConfig;
        } 
        catch (\Exception $e) {
            throw new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage());
        }
    } 
    
    /**
     * Get a login session ID that can be attached to URLs used in ProcessMaker:
     * http://<address>/sys<workspace>/<lang>/<skin>/<folder>/<method>.php?sid=<session-id> 
     * Ex: http://example.com/sysworkflow/en/neoclassic/cases/cases_ShowDocument?a=4699401854d8262f569e9a1070221206&sid=1234567890abcde1234567890abcde 
     * 
     * @url GET /session-id
     * @access protected
     * 
     * @return string The session ID.
     * 
     * @author Amos Batto <amos@processmaker.com>
     * @copyright Public Domain
     */
    public function getSessionId() {  
        try {    
            $g = new G();
            $sessionId = $g->generateUniqueID();
            $userId = $this->getUserId();

            $session = new \Session();
            $session->setSesUid( $sessionId );
            $session->setSesStatus( 'ACTIVE' );
            $session->setUsrUid( $userId );
            $session->setSesRemoteIp( $_SERVER['REMOTE_ADDR'] );
            $session->setSesInitDate( date( 'Y-m-d H:i:s' ) );
            $session->setSesDueDate( date( 'Y-m-d H:i:s', mktime( date('H'), 
                date('i') + 15, date('s'), date('m'), date('d'), date('Y') ) ) );
            $session->setSesEndDate( '' );
            $session->Save();
            return $sessionId;
        } 
        catch (\Exception $e) {
            throw new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage());
        }
    } 
    
    /**
     * Claim a case for the logged-in user where a task is unassigned because the task 
     * is Self Service or Self Service Value Based Assignment.
     * 
     * @url POST /case/:app_uid/claim
     * @access protected
     *
     * @param string $app_uid Case unique ID. {@from path}{@min 1}{@max 32}
     * @param int $del_index  Optional. The delegation index of the task to claim. 
     *  Only include if there are multiple open tasks in the case. {@from body}
     * @param string $usr_uid Optional. Unique ID of the user to assign to case. 
     *  Only include if the logged-in user is a process supervisor assigning another user {@from body}
     */
    public function postClaimCase($app_uid, $del_index = null, $usr_uid = null)
    {
        try {
            $loggedUserId = $this->getUserId();
            $oCase = new \Cases();
            
            if (empty($del_index)) {
               $del_index = $oCase->getCurrentDelegation($app_uid, '', true);
            }

            $oAppDel = new \AppDelegation();
            $aDelegation = $oAppDel->load($app_uid, $del_index);

            if ($aDelegation['USR_UID'] != '') {
                throw new \Exception("The task is already assigned to user with ID '{$aDelegation['USR_UID']}'."); 
            }
            
            if (empty($usr_uid) or $loggedUserId == $usr_uid) {
                $userIdToAssign = $loggedUserId;
            } 
            else {
                //check whether the user exists and has the PM_SUPERVISOR permission in role.
                $rbac = \RBAC::getSingleton();
                $rbac->initRBAC();
            
                if ($rbac->verifyUserId($usr_uid) != 1) {
                    throw new \Exception("User with ID '$usr_uid' does not exist.");
                }
            
                if ($this->userCanAccess('PM_SUPERVISOR') == 0) {
                    throw new \Exception("Logged-in user lacks the PM_SUPERVISOR permission in role.");
                }
                
                //check if logged-in user is assigned as a process supervisor to the process
                $oSuper = new \ProcessMaker\BusinessModel\ProcessSupervisor();
                $aSupervisorList = $oSuper->getProcessSupervisors($aDelegation['PRO_UID'], 'ASSIGNED');
                
                if (!isset($aSupervisorList['data']) or !is_array($aSupervisorList['data'])) {
                    throw new \Exception("Unable to retrieve list of supervisors for process.");
                }
                $isSuperForProcess = false;
                
                foreach ($aSupervisorList['data'] as $aSupervisorInfo) {
                    if ($aSupervisorInfo['usr_uid'] == $loggedUserId) {
                        $isSuperForProcess = true;
                        break;
                    }
                }
                
                if ($isSuperForProcess === false) {
                    throw new \Exception("User '$loggedUserId' must be assigned as a Supervisor for process '".
                       $aDelegation['PRO_UID']."'.");
                }      
                $userIdToAssign = $usr_uid;
            }
            
            $oCase->setCatchUser($app_uid, $del_index, $userIdToAssign);
        } 
        catch (\Exception $e) {
            throw (new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage()));
        }
    } 
    
    /**
     * Route a case to the next task(s) in the process. Unlike the official 
     * PUT /cases/app_uid/route-case endpoint, this endpoint has an option to
     * allow Process Supervisors to route the case. The logged-in user must be
     * assigned as the Supervisor for the case's process.
     *  
     * @url PUT /case/:app_uid/route-case
     * @access protected
     *
     * @param string  $app_uid          Case's unique ID. {@min 32}{@max 32}
     * @param int     $del_index        Optional. Delegation index for current open task in case. Only necessary to specify if the case has multiple open tasks assigned to the logged-in user. {@from body}
     * @param string  $usr_uid          Optional. User assigned to current open task in case. Only necessary to specify if the logged-in user is routing the case as process supervisor. {@from body} 
     * @param boolean $execute_triggers Optional. Set to true if any triggers before assignment should be executed. Default is false. {@from body}
     * 
     * @return array
     * 
     * @author Amos Batto <amos@processmaker.com>
     * @copyright Public Domain
     */
    public function putRouteCase($app_uid, $del_index = null, $usr_uid = null, $execute_triggers = false)
    {
        try {      
            $loggedUserUid = $this->getUserId();
            
            if (!$del_index) {
                $oDelegation = new \AppDelegation(); 
                $del_index = $oDelegation->getCurrentIndex($app_uid);
            }
            
            $oCase = new \Cases();
            $aCaseInfo = $oCase->LoadCase($app_uid, $del_index);
            
            if (empty($usr_uid)) {
                $usr_uid = $aCaseInfo['CURRENT_USER_UID'];
            }
            
            //if the user assigned to the task to be routed is not the same as the logged-in user, 
            //then check if the logged-in user is a Supervisor to the case's Process.
            if ($aCaseInfo['CURRENT_USER_UID'] != $loggedUserUid) {
                
                require_once ("classes/model/ProcessUser.php");
                require_once ("classes/model/Task.php");
                require_once ("classes/model/Process.php");
                
                $oPU = new \ProcessUser();
                
                if ($oPU->validateUserAccess($aCaseInfo['PRO_UID'], $loggedUserUid) == false) {
                    $username = $this->getLoginUser()['username'];
                    $oTask = new \Task();
                    $taskTitle = $oTask->Load($aCaseInfo['TASK'])['TAS_TITLE'];
                    $caseNo = $aCaseInfo['APP_NUMBER'];
                    $oProcess = new \Process();
                    $processTitle = $oProcess->Load($aCaseInfo['PRO_UID'])['PRO_TITLE'];
                    
                    throw new \Exception("Logged-in user '$username' must be assigned to task '$taskTitle' ".
                        "in case #$caseNo or be a Supervisor for process '$processTitle'.");
                }
            }
            
            \G::LoadClass('wsBase');
            $ws = new \wsBase();
            $fields = $ws->derivateCase($usr_uid, $app_uid, $del_index, $execute_triggers);
            $array = json_decode(json_encode($fields), true);
            
            if ($array["status_code"] != 0) {
                throw (new \Exception($array["message"]));
            } 
             
            unset($array['status_code']);
            unset($array['message']);
            unset($array['timestamp']);
            return $array;
        
        } catch (\Exception $e) {
            throw (new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage()));
        }
    }
    
    
    
    /**
     * Reassign a task in a case to another user.
     * Unlike the official PUT /cases/app_uid/reassign-case endpoint, 
     * this endpoint has an option to allow Process Supervisors to reassign the case. 
     * In order to use this endpoint, either the logged-in user must be assigned 
     * to the case or must be assigned as a Supervisor for the case's process. 
     * Note: If the task is configured to send an automatic notification to the next assigned user, 
     * this endpoint will NOT send out that notification. 
     * Instead, execute a trigger that uses PMFSendMessage() to notify the user. 
     * Likewise, it won't execute any triggers associated with task reassignment.
     *  
     * @url PUT /case/:app_uid/reassign-case
     * @access protected
     *
     * @param string  $app_uid        Case unique ID. {@min 32}{@max 32}
     * @param int     $del_index      Optional. Delegation index for the task which will be reassigned. Only necessary to specify if the case has multiple open tasks. {@from body}
     * @param string  $usr_uid_target Optional. ID of the user to whom the task will be reassigned. If not specified, the task will be reassigned to the logged-in user. {@from body} 
     * 
     * @return array
     * 
     * @author Amos Batto <amos@processmaker.com>
     * @copyright Public Domain
     */
    public function putReassignCase($app_uid, $del_index = 0, $usr_uid_target = null)
    {
        try {      
            $loggedUserUid = $this->getUserId();
            $oDelegation = new \AppDelegation();
            
            if ($del_index == 0) {     
                $del_index = $oDelegation->getCurrentIndex($app_uid);
                if ($del_index == null) {
                    throw new \Exception("Case $app_uid either doesn't exist or it has no open tasks.");
                }
            }
            
            $g = new \G();
            $oCase = new \Cases();
            $aCaseInfo = $oCase->LoadCase($app_uid, $del_index);
            
            if (!is_array($aCaseInfo)) {
                throw new \Exception("Case $app_uid doesn't exist or delegation index $del_index is invalid.");
            }
            
            //if no user specified to assign to task, then use the logged-in user 
            if (empty($usr_uid_target)) {
                $usr_uid_target = $loggedUserUid;
            }
            
            if ($usr_uid_target == $aCaseInfo['CURRENT_USER_UID']) {
                throw new \Exception( $g->loadTranslation( 'ID_TARGET_ORIGIN_USER_SAME' ) );
            }            

            //check if target user exists:
            $oCriteria = new \Criteria( 'workflow' );
            $oCriteria->add( UsersPeer::USR_STATUS, 'ACTIVE' );
            $oCriteria->add( UsersPeer::USR_UID, $usr_uid_target );
            $oDataset = \UsersPeer::doSelectRS( $oCriteria );
            $oDataset->setFetchmode( \ResultSet::FETCHMODE_ASSOC );
            $oDataset->next();
            $aRow = $oDataset->getRow();

            if (! is_array( $aRow )) {
                throw new \Exception("The user $usr_uid_target doesn't exist or doesn't have ACTIVE status.");
            }

            //check if the delegation index is OPEN:
            if (!isset($aCaseInfo["DEL_THREAD_STATUS"]) or $aCaseInfo["DEL_THREAD_STATUS"] != 'OPEN' or
                $aCaseInfo["DEL_FINISH_DATE"] != null) 
            {
                throw new \Exception("The task with delegation index $del_index is not open and can't be reassigned.");
            }
            
            //check if the target user is in the assignment list of the task:
            $taskId = $aCaseInfo['TAS_UID'];
            $oDerivation = new \Derivation();
            $aAssignedUsers = $oDerivation->getAllUsersFromAnyTask($taskId, true);

            if ( !in_array($usr_uid_target, $aAssignedUsers) ) {
                throw new \Exception("User '$usr_uid_target' is not assigned to task '$taskId'.");
            }
            
            //if the user assigned to the task is not the same as the logged-in user, 
            //then check if the logged-in user is a Supervisor to the case's Process.
            if ($aCaseInfo['CURRENT_USER_UID'] != $loggedUserUid) {
                
                require_once ("classes/model/ProcessUser.php");
                require_once ("classes/model/Task.php");
                require_once ("classes/model/Process.php");
                
                $oPU = new \ProcessUser();
                
                if ($oPU->validateUserAccess($aCaseInfo['PRO_UID'], $loggedUserUid) == false) {
                    $username = $this->getLoginUser()['username'];
                    $oTask = new \Task();
                    $taskTitle = $oTask->Load($aCaseInfo['TASK'])['TAS_TITLE'];
                    $caseNo = $aCaseInfo['APP_NUMBER'];
                    $oProcess = new \Process();
                    $processTitle = $oProcess->Load($aCaseInfo['PRO_UID'])['PRO_TITLE'];
                    
                    throw new \Exception("Logged-in user '$username' must be assigned to task '$taskTitle' ".
                        "in case #$caseNo or be a Supervisor for process '$processTitle'.");
                }
            }
            
            $result = $oCase->reassignCase($app_uid, $del_index, $aCaseInfo['CURRENT_USER_UID'], $usr_uid_target, 'REASSIGN');
            
            if (!$result) {
                throw new \Exception( $g->loadTranslation( 'ID_CASE_COULD_NOT_REASSIGNED' ) );
            }
            
            //get the delegation index of the reassigned task:
            $newDelIndex = $oDelegation->getCurrentIndex($app_uid);
            
            return array(
                'app_uid'   => $app_uid,
                'del_index' => $newDelIndex,
                'usr_uid'   => $usr_uid_target
            );
        } catch (\Exception $e) {
            throw (new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage()));
        }
    }    
    
    
    /**
     * Uploads a file to a case. Provides more options than the POST /cases/{app_uid}/input-document endpoint.
     * The logged-in user must either be the assigned user to the task indicated by delegation index or
     * be a supervisor to the case's process.   
     * 
     * @url POST /case/:app_uid/upload
     * @access protected
     *
     * @param string $app_uid         Unique ID of case, to which the file will be attached. {@min 32}{@max 32}
     * @param string $doc_type        (optional) Type of file, which can be "ATTACHED" or "INPUT". Set to "ATTACHED" by default if not included. {@body}{@choice ATTACHED,INPUT}
     * @param string $app_doc_uid     (optional) Unique ID of case file (AppDocument) if adding a new version or overwriting an existing case file. If adding a new case file, set to '' (default). {@body}{@max 32}
     * @param int    $doc_version     (optional) Document version, which is set to 1 by default. Don't include if not overwriting an existing version or adding a new version of an existing case file. {@body}
     * @param int    $del_index       (optional) Delegation index. If not included, then set to the current delegation index. {@body}
     * @param string $inp_doc_uid     (optional) Input Document's unique ID or "-1" if not associated with an Input Document. Set to "-1" by default if not included. {@body}{@max 32}
     * @param string $app_doc_comment (optional) Comment about the file {@body} 
     * @param string $field_name      (optional) Name of file field or variable of multipleFile field. If a file or multipleFile field in a grid, set to "<grid-variable> <row-number> <field-id>" Ex: "clientList 2 contractFile" {@body}
     * @param string $field_type      (optional) Type of field which can be "file" (default) or "multipleFile". Not used if field_name is blank or not included. {@body}{@choice file,multipleFile} 
     * @param string $new_file_name   (optional) New file name. If not included, the original filename will be retained. {@body}
     * @param string $folder_uid      (optional) Unique ID of folder where the file will be added, which is "" by default. If an Input Document file, then the folder of the Input Document will be used instead. {@body}
     * 
     * @return array 
     * 
     * @author Amos Batto <amos@processmaker.com>
     * @copyright Public Domain
     */
    public function postUploadFile($app_uid, $doc_type='ATTACHED', $app_doc_uid='', $doc_version=1, $del_index=0, 
       $inp_doc_uid='-1', $app_doc_comment='', $field_name='', $field_type='file', $new_file_name='', $folder_uid='')
    {
        try {            
            $userUid = $this->getUserId();
            
            if ($doc_type == 'INPUT' and (empty($inp_doc_uid) or $inp_doc_uid == '-1')) {
                throw new \Exception("If doc_type is INPUT, then the inp_doc_uid must be specified.");
            }
            
            //if no delegation index, then lookup it up:
            if (!$del_index) {
                $oDelegation = new \AppDelegation(); 
                $del_index = $oDelegation->getCurrentIndex($app_uid);
            }
            
            $oCase = new \Cases();
            $aCaseInfo = $oCase->LoadCase($app_uid, $del_index);
            
            //if the user assigned to the task to be routed is not the same as the logged-in user, 
            //then check if the logged-in user is a Supervisor to the case's Process.
            if ($aCaseInfo['CURRENT_USER_UID'] != $userUid) {
                require_once ("classes/model/ProcessUser.php");
                require_once ("classes/model/Task.php");
                require_once ("classes/model/Process.php");
                
                $oPU = new \ProcessUser();
                
                if ($oPU->validateUserAccess($aCaseInfo['PRO_UID'], $userUid) == false) {
                    $username = $this->getLoginUser()['username'];
                    $oTask = new \Task();
                    $taskTitle = $oTask->Load($aCaseInfo['TASK'])['TAS_TITLE'];
                    $caseNo = $aCaseInfo['APP_NUMBER'];
                    $oProcess = new \Process();
                    $processTitle = $oProcess->Load($aCaseInfo['PRO_UID'])['PRO_TITLE'];
                    
                    throw new \Exception("Logged-in user '$username' must be assigned to task '$taskTitle' ".
                        "in case #$caseNo or be a Supervisor for process '$processTitle'.");
                }
            }                    
            
            if (!isset($_FILES) || !isset($_FILES['file'])) {
               throw new \Exception("No file was uploaded to the 'file' variable.");
            }
            elseif ($_FILES['file']['error'] != 0) {
               throw new \Exception("File upload error number ".$_FILES['file']['error']);
            }
            
            $fileTags = "";

            if ($inp_doc_uid != -1) {
               require_once ("classes/model/AppFolder.php");
               require_once ("classes/model/InputDocument.php");

               $oInputDocument = new \InputDocument();
               $aID = $oInputDocument->load($inp_doc_uid);

               //Get the Custom Folder ID (create if necessary)
               $oFolder = new \AppFolder();
               $folder_uid = $oFolder->createFromPath($aID["INP_DOC_DESTINATION_PATH"], $app_uid);

               //Tags
               $fileTags = $oFolder->parseTags($aID["INP_DOC_TAGS"], $app_uid);
            }

            $filename = !empty($new_file_name) ? $new_file_name : $_FILES['file']['name'];
            $oAppDocument = new \AppDocument();
            
            if (!empty($field_name)) {
                //if a grid field, then set the $field_name and $field_type for grids
                if (preg_match('/^([a-zA-Z0-9_]+) (\d+) ([a-zA-Z0-9_]+)$/', $field_name, $aMatch)) {
                    $gridVarName = $aMatch[1];
                    $gridRowNo   = $aMatch[2];
                    $gridFieldId = $aMatch[3];
                    
                    if ($field_type == 'multipleFile') {
                        $field_name = '['.$aMatch[1].']['.$aMatch[2].']['.$aMatch[3].']';
                        $field_type = 'grid_multipleFile';
                    }
                    else { //if a File field, then use '_' to separate elements
                        $field_name = $aMatch[1].'_'.$aMatch[2].'_'.$aMatch[3];
                        $field_type = 'grid_file';
                    }
                }
            }        

            //if overwriting an existing version of a file or updated the version number
            if ($app_doc_uid != "") { 
               $aFields["APP_DOC_UID"]       = $app_doc_uid;
               $aFields["DOC_VERSION"]       = $doc_version;
               $aFields["APP_DOC_FILENAME"]  = $filename;
               $aFields["USR_UID"]           = $userUid;
               $aFields["APP_DOC_TYPE"]      = $doc_type;
               $aFields["APP_DOC_FIELDNAME"] = $field_name;

               if (!empty($app_uid) and $app_uid != '00000000000000000000000000000000') {
                   $aFields["APP_UID"] = $app_uid;
               }

               if (!empty($del_index)) {
                   $aFields["DEL_INDEX"] = $del_index;
               }

               if (!empty($inp_doc_uid)) {
                   $aFields["DOC_UID"] = $inp_doc_uid;
               }

               $aFields["APP_DOC_CREATE_DATE"] = date("Y-m-d H:i:s");
               $aFields["APP_DOC_COMMENT"] = $app_doc_comment;
               $aFields["APP_DOC_TITLE"] = '';
               $aFields["FOLDER_UID"] = $folder_uid;
               $aFields["APP_DOC_TAGS"] = $fileTags;

            } else { //if a new case file
               $aFields = array(
                   "APP_UID"             => $app_uid,
                   "DEL_INDEX"           => $del_index,
                   "USR_UID"             => $userUid,
                   "DOC_UID"             => $inp_doc_uid,
                   "APP_DOC_TYPE"        => $doc_type,
                   "APP_DOC_CREATE_DATE" => date("Y-m-d H:i:s"),
                   "APP_DOC_COMMENT"     => $app_doc_comment,
                   "APP_DOC_TITLE"       => '',
                   "APP_DOC_FILENAME"    => $filename,
                   "FOLDER_UID"          => $folder_uid, 
                   "APP_DOC_TAGS"        => $fileTags,
                   "APP_DOC_FIELDNAME"   => $field_name
               );
            }

            //add record to database
            $oAppDocument->create( $aFields );

            $sAppUid = $oAppDocument->getAppUid();
            $sAppDocUid = $oAppDocument->getAppDocUid();
            $iDocVersion = $oAppDocument->getDocVersion();
            $info = pathinfo( $oAppDocument->getAppDocFilename() );
            $ext = (isset( $info["extension"] )) ? $info["extension"] : "";

            //Save the file to the server's file system
            $g = new \G();
            $filePath = PATH_DOCUMENT . $g->getPathFromUID($sAppUid) . PATH_SEP;
            $realFilename = $sAppDocUid .'_'. $iDocVersion .'.'. $ext;
            $ret = $g->uploadFile( $_FILES['file']['tmp_name'], $filePath, $realFilename );
            
            //add variable to case for the file:
            if ($field_name != '') {
                
                if ($field_type == 'file' or $field_type == 'grid_file') {
                    $aCaseInfo['APP_DATA'][$field_name] = "[\"$sAppDocUid\"]";
                    $aCaseInfo['APP_DATA'][$field_name.'_label'] = "[\"$filename\"]";
                }
                elseif ($field_type == 'multipleFile') {
                    $aCaseInfo['APP_DATA'][$field_name] = array(
                        'name'      => $filename,
                        'appDocUid' => $sAppDocUid,
                        'version'   => $iDocVersion
                    );
                }
                
                //if a multipleFile field in a grid:
                if (preg_match('/^grid_/', $field_type)) {
                    //add grid variable if it doesn't yet exist in case:
                    if (!isset($aCaseInfo['APP_DATA'][$gridVarName])) {
                        $aCaseInfo['APP_DATA'][$gridVarName] = array();
                    }
                    //add grid row number if it doesn't yet exist
                    if (!isset($aCaseInfo['APP_DATA'][$gridVarName][$gridRowNo])) {
                        $aCaseInfo['APP_DATA'][$gridVarName][$gridRowNo] = array();
                    }
                    
                    if ($field_type == 'grid_multipleFile') {
                        //add multipleFile field in grid row, if it doesn't yet exist:
                        if (!isset($aCaseInfo['APP_DATA'][$gridVarName][$gridRowNo][$gridFieldId])) {
                            $aCaseInfo['APP_DATA'][$gridVarName][$gridRowNo][$gridFieldId] = array();
                        }
                         
                        $aCaseInfo['APP_DATA'][$gridVarName][$gridRowNo][$gridFieldId][] = array(
                            'name'      => $filename,
                            'appDocUid' => $sAppDocUid,
                            'version'   => $iDocVersion
                        );
                    }
                }
                
                $oCase->updateCase($app_uid, array('APP_DATA' => $aCaseInfo['APP_DATA']));
            }
           
            return array(
              'filename'    => $filename,
              'app_doc_uid' => $sAppDocUid,
              'doc_version' => $iDocVersion,
              'app_uid'     => $sAppUid,
              'del_index'   => $del_index,
              'file_path'   => $filePath . $realFilename,
              'url'         => (G::is_https() ? 'https://' : 'http://') . $_SERVER['SERVER_NAME'] .
                               (!in_array($_SERVER['SERVER_PORT'], [80, 443]) ? ':'.$_SERVER[SERVER_PORT] : '') .
                               '/sys'.SYS_SYS.'/en/neoclassic/cases/cases_ShowDocument?a='. $sAppDocUid .'&v='. $iDocVersion
            );
        } catch (\Exception $e) {
            throw (new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage()));
        }
    }
    
    
    
    /**
     * Append records in a PM Table. The logged-in user must have the PM_SETUP and 
     * PM_SETUP_PM_TABLES permissions his/her in role to use this endpoint. 
     * 
     * @url PUT /pmtable/:pmt_uid/append
     * @access protected
     *
     * @param string $pmt_uid Unique ID of PM Table. {@from path}{@min 32}{@max 32}
     * @param array $rows An array of associative arrays where each associative array represents
     * a row to add to the table and its keys are the field names. {@from body}
     * 
     * @return int The number of records inserted in the PM Table.
     */
    public function putAppendToTable($pmt_uid, $rows)
    {
        try {
            if ($this->userCanAccess('PM_SETUP') == 0 or $this->userCanAccess('PM_SETUP_PM_TABLES') == 0) {
                throw new \Exception("Logged-in user lacks the PM_SETUP and PM_SETUP_PM_TABLES permissions in role.");
            }
                
            $oTable = new \ProcessMaker\BusinessModel\Table();
            $count = 0;
            
            foreach ($rows as $row) {
                $response = $oTable->saveTableData($pmt_uid, $row);
                $count++;
            }
            return $count;
        } 
        catch (\Exception $e) {
            throw (new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage()));
        }
    } 
    
    /**
     * Remove all the records in an existing PM Table and then refill the table with
     * new data. The logged-in user must have the PM_SETUP and PM_SETUP_PM_TABLES permissions 
     * his/her in role to use this endpoint. 
     * 
     * @url PUT /pmtable/:pmt_uid/overwrite
     * @access protected
     *
     * @param string $pmt_uid Unique ID of PM Table. {@from path}{@min 32}{@max 32}
     * @param array $rows An array of associative arrays where each associative array represents
     * a row to add to the table and its keys are the field names. {@from body}
     * 
     * @return int The number of records inserted in the PM Table.
     */
    public function putOverwriteTable($pmt_uid, $rows)
    {
        try {
            if ($this->userCanAccess('PM_SETUP') == 0 or $this->userCanAccess('PM_SETUP_PM_TABLES') == 0) {
                throw new \Exception("Logged-in user lacks the PM_SETUP and PM_SETUP_PM_TABLES permissions in role.");
            }
                
            $oTable = new \ProcessMaker\BusinessModel\Table();
            $pmt_uid = $oTable->validateTabUid($pmt_uid, false); //check if a valid PM Table ID
            
            //get class name and table name for PM Table:
            $additionalTables = new \AdditionalTables();
            $aTableProps = $additionalTables->load($pmt_uid, true);
            $className = $aTableProps['ADD_TAB_CLASS_NAME'];
            $tableName = $aTableProps['ADD_TAB_NAME'];

            if (! file_exists( PATH_WORKSPACE . 'classes/' . $className . '.php' )) {
               throw new \Exception( 'Create::' . G::loadTranslation( 'ID_PMTABLE_CLASS_DOESNT_EXIST', $className ) );
            }
            
            require_once PATH_WORKSPACE . 'classes/' . $className . '.php';
            eval( '$con = \\Propel::getConnection(\\' . $className . 'Peer::DATABASE_NAME);' );

            //delete all existing rows in table:
            $con->begin();
            $con->executeQuery('TRUNCATE TABLE '.$tableName);
            $con->commit();
            
            $count = 0;
            foreach ($rows as $row) {
                   $response = $oTable->saveTableData($pmt_uid, $row);
               $count++;
            }
            return count;
        } 
        catch (\Exception $e) {
            throw (new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage()));
        }
    }  
    
        
   /**
     * Retrieve the tree of folders, which is the list of folders 
     * displayed by going to Home > Documents in the ProcessMaker interface. 
     * The logged-in user must have the PM_FOLDERS_VIEW permission in his/her role.
     * 
     * @url GET /documents/:fdr_uid/folders
     * @access protected
     *
     * @param string $fdr_uid The starting folder which can be "root" or the
     * unique ID of the folder. {@from path}
     * 
     * @param int $limit Optional. The maximum number of folders to return. 
     * If set to 0, which is the default, then an unlimited number of folders 
     * will be returned. {@from query}  
     * 
     * @param int $start Optional. The number where to start listing folders. 
     * If set to 0, which is the default, then will start from the first folder. {@from query}
     * 
     * @param string $direction Optional. The sort direction which can be 
     * "ASC" (ascending which is the default) or "DESC" (descending) {@from query}{@choice ASC,DESC} 
     * 
     * @param string $sort Optional. The field used to sort the folders, 
     * which can be "appDocCreateDate" (the date the folder was first created), 
     * "name" (the folder name) or "" (no sort order). {@from query}{@choice appDocCreateDate,name,""}
     * 
     * @param string $search Optional. A case insensitive string to search
     * for in the folder names. Make sure to use a function such as PHP's 
     * url_encode() or JavaScript's encodeURIComponent() so " " becomes %20 and 
     * "é" becomes %C3%A9 {@from query}
     * 
     * @return array An array of folder objects.
     */
    public function getDocumentFolders($fdr_uid, $limit=0, $start=0, $direction='ASC', 
        $sort='appDocCreateDate', $search=null)
    {
        try {
            if ($this->userCanAccess('PM_FOLDERS_VIEW') == 0) {
                throw new \Exception("Logged-in user lacks the PM_FOLDERS_VIEW permission in role.");
            }
            
            if ($fdr_uid == "root") {
                $fdr_uid = "/";
            }
            elseif (!preg_match('/^[0-9a-f]{32}$/', $fdr_uid)) {
                throw new \Exception("Unrecognized fld_uid. Must be 'root' or the unique ID of a document folder.");
            }  
            
            require_once ("classes/model/AppFolder.php");
            $oFolder = new \AppFolder();
        
            $aFolderList = $oFolder->getFolderList($fdr_uid, $limit, $start, 
                $direction, $sort, $search);
                
            return $aFolderList;
        } 
        catch (\Exception $e) {
            throw (new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage()));
        }
    }  
    
    
    /**
     * Retrieve the contents of a folder, which is displayed under Home > Documents 
     * in the ProcessMaker interface. 
     * The logged-in user must have the PM_FOLDERS_VIEW permission in his/her role.
     * 
     * @url GET /documents/:fdr_uid/contents
     * @access protected
     * 
     *
     * @param string $fdr_uid The folder which can be "root" or the
     * unique ID of the folder. {@from path}
     * 
     * @param string $keyword Only return files which have the specified keyword in 
     * the filename or in the tag if "search_type=TAG". {@from query}
     *
     * @param string $search_type The search type, which can be "TAG" or "ALL". {@from query}{@choice TAG,ALL}
     *  
     * @param int $limit Optional. The maximum number of folders to return. 
     * If set to 0, which is the default, then an unlimited number of folders 
     * will be returned. {@from query}  
     * 
     * @param int $start Optional. The number where to start listing folders. 
     * If set to 0, which is the default, then will start from the first folder. {@from query}
     * 
     * @param string $user Only return files that can be accessed by the specified user, 
     * indicated by his/her unique ID. This parameter can only be used if the logged-in
     * user has the PM_ALLCASES permission in his/her role. {@from query} 
     * 
     * @param string $only_active Optional. Set to "true" if only active files (not deleted and 
     * not overwritten with new versions) will be returned. Default is "false" so all files 
     * will be returned. {@from query}{@choice true,false}
     * 
     * @param string $direction Optional. The sort direction which can be 
     * "ASC" (ascending which is the default) or "DESC" (descending) {@from query}{@choice ASC,DESC} 
     * 
     * @param string $sort Optional. The field used to sort the folders, 
     * which can be "appDocCreateDate" (the date the file/folder was created), 
     * "name" (the file/subfolder name) or "" (no sort order). {@from query}{@choice appDocCreateDate,name,""}
     * 
     * @param string $search Optional. A case insensitive string to search
     * for in the filenames and folder names. Make sure to use a function such as PHP's 
     * url_encode() or JavaScript's encodeURIComponent() so " " becomes %20 and 
     * "é" becomes %C3%A9 {@from query}
     * 
     * @return array An array of objects which contain information about files or subfolders.
     */
    public function getDocumentFolderContents($fdr_uid, 
        $keyword = null, $search_type = null, $limit = 0, $start = 0, $user = '', $only_active = 'false', $direction = 'ASC', $sort = 'appDocCreateDate', $search = null)
    {
        try {
            if ($this->userCanAccess('PM_FOLDERS_VIEW') == 0) {
                throw new \Exception("Logged-in user lacks the PM_FOLDERS_VIEW permission in role.");
            }
            
            if ($fdr_uid == "root") {
                $fdr_uid = "/";
            }
            elseif (!preg_match('/^[0-9a-f]{32}$/', $fdr_uid)) {
                throw new \Exception("Unrecognized fld_uid. Must be 'root' or the unique ID of a document folder.");
            }  
            
            if (!empty($user) and $user != $this->getUserId() and $this->userCanAccess('PM_ALLCASES') == 0) {
                throw new \Exception("Logged user needs the PM_ALLCASES permission in role to ".
                    "access documents for another user.");
            }  
            
            require_once ("classes/model/AppFolder.php");
            $oFolder = new \AppFolder();
            //set USER_LOGGED for AppFolder code:
            $_SESSION['USER_LOGGED'] = $this->getUserId();
        
            $aFolderContents = $oFolder->getDirectoryContentSortedBy(
                $fdr_uid, 
                array(),      //$docIdFilter
                $keyword,
                $search_type, 
                $limit, 
                $start, 
                $user, 
                ($only_active == 'true') ? true : false, 
                $direction, 
                $sort,
                $search
            );
            
            $aKeysToReturn = array(
                'APP_DOC_UID',
                'APP_DOC_FILENAME',
                'APP_DOC_COMMENT',
                'DOC_VERSION',
                'APP_UID',
                'DEL_INDEX',
                'DOC_UID',
                'USR_UID',
                'APP_DOC_TYPE',
                'APP_DOC_CREATE_DATE',
                'APP_DOC_INDEX',
                'FOLDER_UID',
                'APP_DOC_PLUGIN',
                'APP_DOC_TAGS',
                'APP_DOC_STATUS',
                'APP_DOC_STATUS_DATE',
                'APP_DOC_FIELDNAME',
                'APP_DOC_DRIVE_DOWNLOAD',
                'SYNC_WITH_DRIVE',
                'SYNC_PERMISSIONS',
                'APP_TITLE',
                'APP_DESCRIPTION',
                'APP_NUMBER',
                'APP_PARENT',
                'APP_STATUS',
                'PRO_UID',
                'APP_PROC_CODE',
                'STATUS',
                'CREATOR',
                'CREATE_DATE',
                'UPDATE_DATE',
                'PRO_TITLE',
                'INP_DOC_UID',
                'INP_DOC_TITLE',
                'INP_DOC_DESCRIPTION',
                'INP_DOC_FORM_NEEDED',
                'INP_DOC_ORIGINAL',
                'INP_DOC_PUBLISHED',
                'INP_DOC_VERSIONING',
                'INP_DOC_DESTINATION_PATH',
                'INP_DOC_TAGS',
                'INP_DOC_TYPE_FILE',
                'INP_DOC_MAX_FILESIZE',
                'INP_DOC_MAX_FILESIZE_UNIT',
                'OUT_DOC_UID',
                'OUT_DOC_TITLE',
                'OUT_DOC_DESCRIPTION',
                'OUT_DOC_FILENAME',
                'OUT_DOC_REPORT_GENERATOR',
                'OUT_DOC_LANDSCAPE',
                'OUT_DOC_MEDIA',
                'OUT_DOC_GENERATE',
                'OUT_DOC_TYPE',
                'OUT_DOC_CURRENT_REVISION',
                'OUT_DOC_FIELD_MAPPING',
                'OUT_DOC_VERSIONING',
                'OUT_DOC_DESTINATION_PATH',
                'OUT_DOC_TAGS',
                'OUT_DOC_PDF_SECURITY_ENABLED',
                'OUT_DOC_PDF_SECURITY_OPEN_PASSWORD',
                'OUT_DOC_PDF_SECURITY_OWNER_PASSWORD',
                'OUT_DOC_PDF_SECURITY_PERMISSIONS',
                'OUT_DOC_OPEN_TYPE',
                'USR_USERNAME',
                'USR_FIRSTNAME',
                'USR_LASTNAME',
                'DELETE_LABEL',
                'DOWNLOAD_LABEL',
                'DOWNLOAD_LINK',
                'DOWNLOAD_LABEL1',
                'DOWNLOAD_LINK1',
                'APP_DOC_UID_VERSION'
            );
            
            //remove information which could be a security risk and is unnecessary:
            if (is_array($aFolderContents) and isset($aFolderContents['documents'])) {
                for ($i = 0; $i < count($aFolderContents['documents']); $i++) {
                    foreach ($aFolderContents['documents'][$i] as $key => $val) {
                        if (!in_array($key, $aKeysToReturn)) {
                            unset($aFolderContents['documents'][$i][$key]);
                        }
                    }
                }
            }
            
            return $aFolderContents;
        } 
        catch (\Exception $e) {
            throw (new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage()));
        }
    } 
    
    /**
     * Get the case counters for a user, meaning the number of To Do, Draft, 
     * Paused, Cancelled, Participated, Unassigned and Process Supervisor's Review cases. 
     * If a user's unique ID is not specified in the query string, then the 
     * case counters will be for the current logged-in user. If getting the case
     * counters for another user, then the PM_ALLCASE permission needs to be
     * in the logged user's role. 
     * 
     * @url GET /cases/counters
     * @access protected
     *
     * @param string $usr_uid Optional. Unique ID of a user. If not included 
     * then will return the case counters for the current logged-in user. {@from query}{@min 32}{@max 32}
     * 
     * @return array An array with the number of cases in the following categories: 
     * array(
     *    'to_do'      => X, //number of To Do cases
     *    'draft'      => X, //number of Draft cases
     *    'cancelled'  => X, //number of Cancelled cases
     *    'sent'       => X, //number of Participated cases
     *    'paused'     => X, //number of Paused cases
     *    'completed'  => X, //number of Completed cases
     *    'selfservice'=> X, //number of Unassigned cases (for tasks with 
     *                       //Self Service o Self Service Value Based Assignment)
     *    'to_revise'  =  X, //number of Process Supervisor > Review cases
     * ) 
     */
    public function getCaseCounters($usr_uid=null)
    {
        try {
            if (empty($usr_uid)) {
                $usr_uid = $this->getUserId();
            }
            
            if ($usr_uid != $this->getUserId() and $this->userCanAccess('PM_ALLCASES') == 0 ) {
                throw new \Exception("Logged-in user needs the PM_ALLCASES permission to access ".
                    "the case counters of other users.");
            }
            
            $aTypes = array('to_do', 'draft', 'cancelled', 'sent', 'paused', 'completed', 'selfservice', 'to_revise', 'to_reassign');            
            $oCase = new \ProcessMaker\BusinessModel\Cases();
            $aCount = $oCase->getListCounters($usr_uid, $aTypes);

            return $aCount;
        } 
        catch (\Exception $e) {
            throw (new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage()));
        }
    }
    
    
    /**
     * Execute trigger with input and output variables. 
     * Unlike the official endpoint PUT /cases/{app_uid}/execute-trigger/{tri_uid},
     * this endpoint has a option to write variables to the case before executing the trigger
     * and another option to specify case variables which should be returned after executing the trigger.
     * There is also an option to restore the case variables to their original state before 
     * the trigger was executed.
     * 
     * Security: In order to execute this endpoint, the logged-in user needs to either 
     * be currently assigned to the open delegation specified by the del_index parameter or 
     * the last opened delegation if the del_index isn't specified or needs to 
     * be assigned as a process supervisor to the case's process. 
     * 
     * @url PUT /case/:app_uid/execute-trigger/:tri_uid
     * @access protected
     * 
     * @param string $app_uid   Unique ID of case where the trigger will be executed. {@from path}{@min 32}{@max 32}
     * 
     * @param string $tri_uid   Unique ID of the trigger to execute. {@from path}{@min 32}{@max 32}
     * 
     * @param int $del_index    Optional. Delegation index where the trigger will be executed. 
     * If not included or set to -1, then it will automatically executed in the open task 
     * where the usr_uid is assigned. If there is no open task assigned to the user, 
     * then an error will be returned. {@from body}{@min -1}
     *
     * @param array $input_vars   Optional. A JSON string containing an object of variables 
     * to set in the case before executing the trigger. 
     * Use this format: {var1:"value1",var2:"value2"} {@from body}
     * 
     * @param string $return_vars   Optional. Which type of variables should be returned from the case 
     * after executing the trigger: NONE (default), ALL, ONLY_CASE, ONLY_SYSTEM, SPECIFY, ALL_EXCEPT 
     * {@from body}{@choice NONE,ALL,ONLY_CASE,ONLY_SYSTEM,SPECIFY,ALL_EXCEPT} 
     * 
     * @param string $list_vars   Optional. If return_vars is set to 'SPECIFY' or 'ALL_EXCEPT', 
     * then list the names of the case variables separated by commas to return. 
     * Note: If a specified variable is missing, then it will be listed in a '__MISSING_VARS__' element 
     * in the return array. {@from body}
     * 
     * @param int $revert_vars   Optional. If set to 1, then the case's variables will be reverted 
     * to their original state before the trigger was executed. 
     * Set to 0 or don't include to not revert the case variables. 
     * Remember that reverting will remove the __ERROR__ variable if it was set, 
     * so don't use this option if needing to debug. {@from body} 
     *  
     * @return array   An array with the case variables indicated by the output_vars parameter: 
     * array(
     *    'variable1' => value1,
     *    'variable2' => value2,
     *    ...
     * ) 
     */
    public function putExecuteTriggerIO($app_uid, $tri_uid, $del_index=-1, 
        $input_vars=array(), $return_vars='NONE', $list_vars='', $revert_vars=0)
    {
        try {
            
            global $RBAC;
            if (!method_exists($RBAC, 'initRBAC')) {
                $RBAC = \RBAC::getSingleton(PATH_DATA, session_id());
                $RBAC->sSystem = 'PROCESSMAKER';
            }

            //\Validator::appUid($app_uid, '$app_uid');
            //\Validator::triUid($tri_uid, '$tri_uid');
                
            $oCase = new \Cases();
            
            if ($del_index == -1) {
                //Find the last open delegation assigned to the logged-in user:
                $oCaseBM = new \ProcessMaker\BusinessModel\Cases();
                $del_index = (integer) $oCaseBM->getLastParticipatedByUser($app_uid, $this->getUserId(), 'OPEN');
                
                //if $del_index is set to 0, then there is no open delegation assigned to the user:
                if ($del_index === 0) {
                    
                    //if the logged-in user is a Supervisor to the case's Process, then can execute 
                    //the trigger without being assigned to open delegation:
                    $oPU = new \ProcessUser();
                    $aCaseInfo = $oCase->LoadCase($app_uid);
                    
                    if ($oPU->validateUserAccess($aCaseInfo['PRO_UID'], $this->getUserId()) == false) {
                        $username = $this->getLoginUser()['username'];
                        $caseNo = $aCaseInfo['APP_NUMBER'];
                        $oProcess = new \Process();
                        $processTitle = $oProcess->Load($aCaseInfo['PRO_UID'])['PRO_TITLE'];
                        
                        throw new \Exception("Logged-in user '$username' must be assigned to an open task ".
                            "in case #$caseNo or be a Supervisor for process '$processTitle'.");
                    }
                    
                    $del_index = $oCase->getCurrentDelegation($app_uid, '', true);
                }
            }
            
            $aCaseInfo = $oCase->LoadCase($app_uid, $del_index);
        
            if ($aCaseInfo['DEL_THREAD_STATUS'] != 'OPEN') {
                $caseNo = $aCaseInfo['APP_NUMBER'];
                $oTask = new \Task();
                $taskTitle = $oTask->Load($aCaseInfo['TASK'])['TAS_TITLE'];
                throw new \Exception("Trigger cannot be executed because delegation $del_index in task '$taskTitle' in case#$caseNo is not open.");
            }
            
            //if the logged in user, isn't assigned to the specified delegation in case:
            if ($aCaseInfo['CURRENT_USER_UID'] != $this->getUserId()) {

                //check if the logged-in user is a Supervisor to the case's process, so can execute in the user's name.
                if ($oPU->validateUserAccess($aCaseInfo['PRO_UID'], $this->getUserId()) == false) {
                    $username = $this->getLoginUser()['username'];
                    $oTask = new \Task();
                    $taskTitle = $oTask->Load($aCaseInfo['TASK'])['TAS_TITLE'];
                    $caseNo = $aCaseInfo['APP_NUMBER'];
                    $oProcess = new \Process();
                    $processTitle = $oProcess->Load($aCaseInfo['PRO_UID'])['PRO_TITLE'];
                    
                    throw new \Exception("Logged-in user '$username' must be assigned to task '$taskTitle' ".
                        "in case #$caseNo or be a Supervisor for process '$processTitle'.");
                }
            }
            
            $userUid = $aCaseInfo['CURRENT_USER_UID'];
            $aInputVars = $input_vars;
                            
            if ($aInputVars === null) {
                throw new \Exception("Bad JSON string in parameter json_input_vars.");
            }
            
            if ($revert_vars) {
                $aOriginalVars = $aCaseInfo['APP_DATA'];
            }
            
            if (!empty($aInputVars)) {
                foreach ($aInputVars as $varName => $varValue) {
                    $aCaseInfo['APP_DATA'][$varName] = $varValue;
                }
                
                $aCaseToUpdate = array(
                    'APP_UID'    => $app_uid,
                    'APP_DATA'   => $aCaseInfo['APP_DATA']
                );
                
                $oCase->updateCase($app_uid, $aCaseToUpdate);
            }
                
            $oWS = new \WsBase();
            $result = $oWS->executeTrigger($userUid, $app_uid, $tri_uid, $del_index);

            if ($result->status_code != 0) {
                throw new \Exception($result->message);
            }
            
            $aOutputVars = array();
            $aCaseInfo = $oCase->LoadCase($app_uid);
            $aSystemVars = array('SYS_SYS', 'SYS_LANG', 'SYS_SKIN', 'APPLICATION', 'APP_NUMBER', 
                'INDEX', 'PROCESS', 'TASK', 'USER_LOGGED', 'USR_USERNAME', 'PIN', '__ERROR__', '__VAR_CHANGED__'); 
            
            if ($return_vars == 'ALL') {
                $aOutputVars = $aCaseInfo['APP_DATA'];
            }
            elseif ($return_vars == 'ONLY_CASE') {
                $aOutputVars = $aCaseInfo['APP_DATA'];
                
                foreach ($aSystemVars as $varName) {
                    if (isset($aOutputVars[$varName])) {
                        unset($aOutputVars[$varName]);
                    }
                }
            }
            elseif ($return_vars == 'ONLY_SYSTEM') {
                foreach ($aSystemVars as $varName) {
                    if (isset($aCaseInfo['APP_DATA'][$varName])) {
                        $aOutputVars[$varName] = $aCaseInfo['APP_DATA'][$varName];
                    }
                }
            }
            elseif ($return_vars == 'SPECIFY') {
                $aVarsToReturn = split(',', $list_vars);
                $aMissingVars = array();
                
                foreach ($aVarsToReturn as $varToReturn) {
                    $varToReturn = trim($varToReturn);
                    
                    if (!isset($aCaseInfo['APP_DATA'][$varToReturn])) {
                        $aMissingVars[] = $varToReturn;
                    }
                    else {
                        $aOutputVars[$varToReturn] = $aCaseInfo['APP_DATA'][$varToReturn];
                    }
                }
                
                if (!empty($aMissingVars)) {
                    $aOutputVars['__MISSING_VARS__'] = implode(',', $aMissingVars);
                }
            }
            elseif ($return_vars == 'ALL_EXCEPT') {
                $aVarsToReturn = split(',', $list_vars);
                $aOutputVars = $aCaseInfo['APP_DATA'];
                $aMissingVars = array();
                
                foreach ($aVarsToReturn as $varToReturn) {
                    $varToReturn = trim($varToReturn);
                    
                    if (!isset($aOutputVars[$varToReturn])) {
                        $aMissingVars[] = $varToReturn;
                    }
                    else {
                        unset($aOutputVars[$varToReturn]);
                    }
                }
                
                if (!empty($aMissingVars)) {
                    $aOutputVars['__MISSING_VARS__'] = implode(',', $aMissingVars);
                }
            }    

            
            if ($revert_vars) {
                $aCaseToUpdate = array(
                    'APP_UID'    => $app_uid,
                    'APP_DATA'   => $aOriginalVars
                );
                $oCase->updateCase($app_uid, $aCaseToUpdate);
            }
            
            return $aOutputVars;
        } 
        catch (\Exception $e) {
            throw (new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage()));
        }
    }
    
    /**
     * Get the properties of a Dynaform. 
     * 
     * @url GET /dynaform/:dyn_uid
     * @access protected
     *
     * @param string $dyn_uid  Unique ID of a dynaform. {@from path}{@min 32}{@max 32}
     * @param string $app_uid  Optional. Unique ID of a case. {@from query}{@max 32}
     * @param int $del_index   Optional. Delegation index. {@from query}{@min -1}
     * 
     * @return array
     */
    public function getDynaformProps($dyn_uid, $app_uid='', $del_index=-1)
    {
        try {
            $fields = array();
            $data = array();

            if (!empty($app_uid) and $del_index != -1) {
                $cases = new \Cases();
                $data = $cases->loadCase($app_uid, $del_index);
            } 
            
            $data["CURRENT_DYNAFORM"] = $dyn_uid;

            $dynaform = new \PmDynaform(\ProcessMaker\Util\DateTime::convertUtcToTimeZone($data));
            $dynaform->onPropertyRead = function(&$json, $key, $value) {
                if (isset($json->data) && !isset($json->value)) {
                    $json->value = $json->data->value;
                    $json->value_label = $json->data->label;
                }
            };

            if ($dynaform->isResponsive()) {
                $json = \G::json_decode($dynaform->record["DYN_CONTENT"]);
                //$dynaform->jsonr($json);
                $rows = $json->items[0]->items;
                
                foreach ($rows as $items) {
                    foreach ($items as $item) {
                        $fields[] = $item;
                    }
                }
            } else {
                $oldDynaform = new \Dynaform();
                $aFields = $oldDynaform->getDynaformFields($dyn_uid);
                foreach ($aFields as $value) {
                    if (isset($data["APP_DATA"]) && isset($data["APP_DATA"][$value->name])) {
                        $value->value = $data["APP_DATA"][$value->name];
                    }
                    $fields[] = $value;
                }
            }
            return $fields;
        } 
        catch (\Exception $e) {
            throw (new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage()));
        }
    }
    
    
    /**
     * Get the starting task(s) for a specified project/process. Unlike the
     * official GET /project/{prj_uid}/starting-tasks endpoint which only gets the starting tasks
     * assigned to the logged-in user, this endpoint can get the starting task(s)
     * assigned to a specified user. 
     * 
     * @url GET /project/:prj_uid/starting-tasks
     * @access protected
     * 
     * @param string $prj_uid  The unique ID of a project or process. {@from path}{@min 32}{@max 32}
     * @param string $usr_uid  Optional. The unique ID of the user which is assigned to the starting tasks. 
     * If left empty, then the logged-in user will be used {@from query}{@max 32} 
     * 
     * @return array  In the format:
     * [
     *   {
     *     "act_uid":  "{task_uid}",
     *     "act_name": "{task_title}"
     *   },
     *   ...
     * ]
     * 
     * @author Amos Batto <amos@processmaker.com>
     * @copyright Public Domain
     */
    public function getStartingTasksAssignedToUser($prj_uid, $usr_uid='')
    {  
        try {
            if (empty($usr_uid)) {
                $usr_uid = $this->getUserId();
            }   
                 
            $oPrjUser = new \ProcessMaker\BusinessModel\ProjectUser();
            $aTasks = $oPrjUser->getProjectStartingTaskUsers($prj_uid, $usr_uid);
            
            return $aTasks;
        } catch (\Exception $e) {
            throw new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage());
        }
    }
    
    
    /**
     * Get the next task(s) in a case, which is useful to know where a case will go next
     * before executing cases/{app_uid}/route-case.
     *   
     * This endpoint should only be called for an open delegation in a case. Only the currently
     * assigned user to the case or a Supervisor to the case's process can call this endpoint. 
     * 
     * @url GET /case/:app_uid/next-tasks
     * @access protected
     * 
     * @param string $app_uid          The unique ID of a case. {@from path}{@min 32}{@max 32}
     * @param int    $del_index        Optional. The index of an open delegation in a case. If not specified,
     *                                 then the last open delegation assigned to the logged-in user will be looked up. 
     *                                 {@from query}{@min 0}
     * @param string $execute_triggers Optional. If set to "yes", then execute any triggers 
     *                                 before assignment and before routing, which is recommended
     *                                 if the routing depends on case variables. Default is "no". 
     *                                 {@from query}{@choice yes,no}
     * @param string $revert_vars      Optional. If executing triggers and set to "yes" (which is the default), 
     *                                 the case variables will be reverted, so the data isn't changed by executing 
     *                                 the triggers. To not revert the variables, set to "no". (@from query}{@choice yes,no}  
     * 
     * @return array 
     * 
     * @author Amos Batto <amos@processmaker.com>
     * @copyright Public Domain
     */
    public function getNextTasksInCase($app_uid, $del_index=0, $execute_triggers='no', $revert_vars='yes')
    {  
        try {
            $g = new \G();
            $g->sessionVarSave();
            $loggedUserId = $this->getUserId();

            $_SESSION["APPLICATION"] = $app_uid;

            //Define variables
            $sStatus = 'TO_DO';
            $varResponse = '';
            $previousAppData = [];

            if ($del_index == 0) {
                $oCriteria = new \Criteria('workflow');
                $oCriteria->addSelectColumn(\AppDelegationPeer::DEL_INDEX);
                $oCriteria->add(\AppDelegationPeer::APP_UID, $app_uid);
                $oCriteria->add(\AppDelegationPeer::USR_UID, $loggedUserId);
                $oCriteria->add(\AppDelegationPeer::DEL_FINISH_DATE, null, Criteria::ISNULL);

                if (\AppDelegationPeer::doCount($oCriteria) > 1) {
                    throw new \Exception( \G::LoadTranslation('ID_SPECIFY_DELEGATION_INDEX') );
                }

                $oDataset = \AppDelegationPeer::doSelectRS($oCriteria);
                $oDataset->setFetchmode(\ResultSet::FETCHMODE_ASSOC);
                $oDataset->next();
                $aRow = $oDataset->getRow();
                $del_index = $aRow['DEL_INDEX'];
            }
            
            $_SESSION["INDEX"] = $del_index;

            $oAppDel = new \AppDelegation();
            $aDelegation = $oAppDel->Load($app_uid, $del_index);
            $userId = $aDelegation['USR_UID'];
            $_SESSION["USER_LOGGED"] = $userId;
            //return array("here zero");
            //if the logged-in user is not assigned to the task, then 
            //check if logged-in user is assigned as a process supervisor to the case's process
            if ($userId != $loggedUserId) {
                $oSuper = new \ProcessMaker\BusinessModel\ProcessSupervisor();
                $aSupervisorList = $oSuper->getProcessSupervisors($aDelegation['PRO_UID'], 'ASSIGNED');
                
                if (!isset($aSupervisorList['data']) or !is_array($aSupervisorList['data'])) {
                    throw new \Exception("Unable to retrieve list of supervisors for process.");
                }
                $isSuperForProcess = false;
                
                foreach ($aSupervisorList['data'] as $aSupervisorInfo) {
                    if ($aSupervisorInfo['usr_uid'] == $loggedUserId) {
                        $isSuperForProcess = true;
                        break;
                    }
                }
                
                if ($isSuperForProcess === false) {
                    throw new \Exception("User '$loggedUserId' must be assigned as a Supervisor for process '".
                       $aDelegation['PRO_UID']."'.");
                } 
                
            }
            
            if ($aDelegation['DEL_FINISH_DATE'] != null) {
                
                $msg = "Delegation {$aDelegation['DEL_INDEX']} assigned to user '{$aDelegation['USR_UID']}' ".
                    "was closed on {$aDelegation['DEL_FINISH_DATE']}.";
                throw new \Exception($msg);
            }

            //Validate if the case is paused or cancelled
            $oAppDelay = new \AppDelay();
            $aRow = $oAppDelay->getCasesCancelOrPaused($app_uid);
            if (is_array($aRow)) {
                if (isset($aRow['APP_DISABLE_ACTION_USER']) && $aRow['APP_DISABLE_ACTION_USER'] != 0 && 
                    isset($aRow['APP_DISABLE_ACTION_DATE']) && $aRow['APP_DISABLE_ACTION_DATE'] != '') 
                {
                    throw new \Exception( \G::LoadTranslation('ID_CASE_IN_STATUS') . " " . $aRow['APP_TYPE'] );
                }
            }

            $aData = [];
            $aData['APP_UID'] = $app_uid;
            $aData['DEL_INDEX'] = $del_index;
            $aData['USER_UID'] = $userId;

            //Load data
            $oCase = new \Cases();
            $appFields = $oCase->loadCase($app_uid, $del_index);
            $aOriginalData = $appFields["APP_DATA"];

            if (is_null($appFields["DEL_INIT_DATE"])) {
                $oCase->setDelInitDate($app_uid, $del_index);
                $appFields = $oCase->loadCase($app_uid, $del_index);
            }
            unset($appFields['APP_ROUTING_DATA']);

            $appFields["APP_DATA"]["APPLICATION"] = $app_uid;
            $_SESSION["PROCESS"] = $appFields["PRO_UID"];


            if ($execute_triggers == 'yes') {
                global $oPMScript;
                $oWS = new \WsBase();

                if (isset($oPMScript->aFields['APPLICATION']) && ($oPMScript->aFields['APPLICATION'] != $app_uid)) {
                    $previousAppData = $oPMScript->aFields;
                }

                $varTriggers = "\n";
                //Execute triggers before assignment
                if ($bExecuteTriggersBeforeAssignment) {
                    $varTriggers .= $oWS->executeTriggerFromDerivate(
                        $app_uid,
                        $appFields["APP_DATA"],
                        $aDelegation['TAS_UID'],
                        'ASSIGN_TASK',
                        -1,
                        'BEFORE',
                        "-= Before Assignment =-"
                    );
                }
                
                $appFields = $oCase->loadCase($app_uid); //get updated case data

                //Execute triggers before routing
                $varTriggers .= $oWS->executeTriggerFromDerivate(
                    $app_uid,
                    $appFields["APP_DATA"],
                    $aDelegation['TAS_UID'],
                    'ASSIGN_TASK',
                    -2,
                    'BEFORE',
                    "-= Before Derivation =-"
                );
            }

            $oDerivation = new \Derivation();
            $aRoutingInfo = $oDerivation->prepareInformation($aData);
            
            if ($execute_triggers == 'yes' && $revert_vars == 'yes') {
                $aCaseData = array(
                   'APP_UID'  => $app_uid,
                   'APP_DATA' => $aOriginalData
                );
                $oCase->updateCase($app_uid, $aCaseData);
            }
            
            return $aRoutingInfo;
        } 
        catch (\Exception $e) {
            throw new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage());
        }
    }
    
    /**
     * Generate a specified Output Document for a given case, meaning that a PDF, 
     * a DOC or both files (depending on options selected in the definition of the 
     * Output Document) will be created, inserting any variables in the template. 
     * If the Output Document already exists, then it will be regenerated. 
     * If versioning is enabled, then the regenerated files will be given a new 
     * version number and document index number, but if versioning is NOT enabled, 
     * then the existing files will be overwritten with the same version number 
     * and document index number. 
     * 
     * Unlike the official endpoint POST /{app_uid}/{del_index}/output-document/{out_doc_uid}, 
     * this endpoint will look up the delegation index if not specified and allows 
     * Process Supervisors to generate Output Documents for another user assigned to the case. 
     * 
     * @url POST /case/:app_uid/output-document/:out_doc_uid
     * 
     * @param string  $app_uid     Unique ID of open case where the Output Document will be generated. 
     *                             {@min 32}{@max 32}
     * @param string  $out_doc_uid Unique ID of the Output Document definition. {@min 32}{@max 32}
     * @param int     $del_index   Optional. Open delegation index where Output Document will be generated. 
     *                             {@from body}{@min 0} 
     * @param string  $usr_uid     Optional. Unique ID of user who will generate the Output Document. 
     *                             Note that the logged-in user needs to be a Supervisor to the case's process to 
     *                             generate for another user. {@from body}{@max 32}
     *                            
     * @return object
     * @throws RestException 
     * 
     * @access protected
     * @class AccessControl {@permission PM_CASES}
     */
    public function postGenerateOutputDocument($app_uid, $out_doc_uid, $del_index=0, $usr_uid='')
    {
        try {
            $loggedUserId = $this->getUserId();
            
            if (empty($usr_uid)) {
                $usr_uid = $loggedUserId;
            }
            
            $oCase = new \Cases();
            
            if (empty($del_index)) {
               $del_index = $oCase->getCurrentDelegation($app_uid, $usr_uid, true);
            }

            $oAppDel = new \AppDelegation();
            $aDelegation = $oAppDel->load($app_uid, $del_index);

            if ($aDelegation['USR_UID'] != $usr_uid) {
                throw new \Exception("The task is assigned to another user with ID '{$aDelegation['USR_UID']}'."); 
            }
            
            if ($usr_uid != $loggedUserId) {
                //check whether the user exists and has the PM_SUPERVISOR permission in role.
                $rbac = \RBAC::getSingleton();
                $rbac->initRBAC();
            
                if ($rbac->verifyUserId($usr_uid) != 1) {
                    throw new \Exception("User with ID '$usr_uid' does not exist.");
                }
            
                if ($this->userCanAccess('PM_SUPERVISOR') == 0) {
                    throw new \Exception("Logged-in user lacks the PM_SUPERVISOR permission in role.");
                }
                
                //check if logged-in user is assigned as a process supervisor to the process
                $oSuper = new \ProcessMaker\BusinessModel\ProcessSupervisor();
                $aSupervisorList = $oSuper->getProcessSupervisors($aDelegation['PRO_UID'], 'ASSIGNED');
                
                if (!isset($aSupervisorList['data']) or !is_array($aSupervisorList['data'])) {
                    throw new \Exception("Unable to retrieve list of supervisors for process.");
                }
                $isSuperForProcess = false;
                
                foreach ($aSupervisorList['data'] as $aSupervisorInfo) {
                    if ($aSupervisorInfo['usr_uid'] == $loggedUserId) {
                        $isSuperForProcess = true;
                        break;
                    }
                }
                
                if ($isSuperForProcess === false) {
                    throw new \Exception("User '$loggedUserId' must be assigned as a Supervisor for process '".
                       $aDelegation['PRO_UID']."'.");
                }      
            }

            //$case = new \ProcessMaker\BusinessModel\Cases();
            $outputDocument = new \ProcessMaker\BusinessModel\Cases\OutputDocument();
            $outputDocument->throwExceptionIfCaseNotIsInInbox($app_uid, $del_index, $usr_uid);
            $outputDocument->throwExceptionIfOuputDocumentNotExistsInSteps($app_uid, $del_index, $out_doc_uid);
            
            return $outputDocument->addCasesOutputDocument($app_uid, $out_doc_uid, $usr_uid);
        } 
        catch (\Exception $e) {
            throw new RestException(Api::STAT_APP_EXCEPTION, $e->getMessage());
        }
    }
    

}    


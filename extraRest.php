<?php
G::LoadClass("plugin");

class extraRestPlugin extends PMPlugin
{
  
  
  public function extraRestPlugin($sNamespace, $sFilename = null)
  {
    $res = parent::PMPlugin($sNamespace, $sFilename);
    $this->sFriendlyName   = "extraRest Plugin";
    $this->sDescription    = "Extra REST endpoints for ProcessMaker 3 by Amos Batto (amos@processmaker.com)";
    $this->sPluginFolder   = "extraRest";
    $this->sSetupPage      = ""; 
    $this->iVersion        = 1.12;
    $this->iPMVersion      = 330;
    $this->aWorkspaces     = null;
    //$this->aWorkspaces   = array("os");
    $this->enableRestService(true);   
    
    
    return $res;
  }

  public function setup()
  {
    
    
  }

  public function install()
  {
  }
  
  public function enable()
  {
    
  }

  public function disable()
  {
    
  }
  
}

$oPluginRegistry = PMPluginRegistry::getSingleton();
$oPluginRegistry->registerPlugin("extraRest", __FILE__);

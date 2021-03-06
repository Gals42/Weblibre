<?php

/**
 * This file is part of the Weblibre
 *
 * Copyright (c) 2012 Radim Kocman (xkocma03)
 * @author  Radim Kocman 
 */

/**
 * Base class for signed-only sections
 *
 * @author  Radim Kocman
 */
abstract class SignedPresenter extends BasePresenter 
{
  /** 
   * Mode of results
   * @var string
   * @persistent
   */
  public $mode = 'Modern';
  
  /** 
   * Checks after start
   * @return void
   */
  protected function startup() 
  {
    parent::startup();
    
    // Check signed user
    if (!$this->user->isLoggedIn())
      $this->redirect('Sign:');
    
    // Check mode
    if ($this->mode != 'Modern' && $this->mode != 'Classic')
      $this->mode = 'Modern';
  }
  
  /**
   * Send data into template
   * @return void
   */
  protected function beforeRender() 
  {
    parent::beforeRender();
    
    // User data
    $this->template->user = $this->user->getIdentity()->getData();
    
    // Menu
    $this->template->menu = $this->menuCheckTest(array(
      array(
        'title' => 'Browse library',
        'href'  => 'Browse:',
        'check' => array(
          'Browse:*', 'Book:*', 'Add:format', 'Edit:*', 'View:*', 'Convert:*'
        )
      ),
      array(
        'title' => 'Add new books',
        'href'  => 'Add:',
        'check' => array('Add:default')
      ),
    ));
    
    // Navigation
    $this->template->navigation = array(
      array(
        'title' => 'Weblibre',
        'href'  => 'Browse:',
        'translate' => false,
        'id' => NULL
      )
    );
  }
  
  /**
   * Menu check test
   * @param array $menu Array with menu
   * @return array Array with tested menu
   */
  private function menuCheckTest($menu) 
  {
    $menuChecked = array();
    foreach($menu as $item) {
      foreach($item['check'] as $check) {
        if ($this->isLinkCurrent($check)) {
          $item['selected'] = true;
          break;
        }
      }
      if (!isset($item['selected']))
        $item['selected'] = false;
      $menuChecked[] = $item;
    }
    return $menuChecked;
  }
  
  /**
   * Add item into navigation
   * @param string $title Shown title
   * @param string $href Link - can be empty string
   * @param bool $translate Should be title translated?
   * @param int $id Id for specific subsection
   * @return void
   */
  protected function addNavigation($title, $href, $translate=true, $id=NULL) 
  {
    $array[0]['title'] = $title;
    if (!empty($href))  $array[0]['href']  = $href;
    $array[0]['translate'] = $translate;
    $array[0]['id'] = $id;
    $this->template->navigation = array_merge(
      $this->template->navigation, $array
    );
  }
  
}

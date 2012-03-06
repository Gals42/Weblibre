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
   * Check signed user
   * @return void
   */
  protected function startup() {
    parent::startup();
    
    if (!$this->user->isLoggedIn())
      $this->redirect('Sign:');
  }
  
  /**
   * Send data into template
   * @return void
   */
  protected function beforeRender() {
    parent::beforeRender();
    
    // User data
    $this->template->user = $this->user->getIdentity()->getData();
    
    // Menu
    $this->template->menu = array(
      array(
        'title' => 'Browse library',
        'href'  => 'Browse:',
        'check' => 'Browse:*'
      ),
      array(
        'title' => 'Add new books',
        'href'  => 'Add:',
        'check' => 'Add:*'
      ),
    );
    
    // Navigation
    $this->template->navigation = array(
      array(
        'title' => 'Weblibre',
        'href'  => 'Browse:',
        'translate' => false
      )
    );
  }
  
  /**
   * Add item into navigation
   * @param string $title
   * @param string $href
   * @return void
   */
  protected function addNavigation($title, $href, $translate=true) {
    $array[0]['title'] = $title;
    if (!empty($href))  $array[0]['href']  = $href;
    $array[0]['translate'] = $translate;
    $this->template->navigation = array_merge(
      $this->template->navigation, $array
    );
  }
  
}
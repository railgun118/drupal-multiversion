<?php

/**
 * @file
 * Contains \Drupal\multiversion\Tests\MenuLinkTest.
 */

namespace Drupal\multiversion\Tests;

use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\multiversion\Entity\Workspace;
use Drupal\simpletest\WebTestBase;

/**
 * Tests menu links deletion.
 *
 * @group multiversion
 */
class MenuLinkTest extends WebTestBase {

  protected $strictConfigSchema = FALSE;

  /**
   * @var \Drupal\multiversion\Workspace\WorkspaceManager
   */
  protected $workspaceManager;

  /**
   * @var \Drupal\multiversion\Entity\WorkspaceInterface
   */
  protected $initialWorkspace;

  /**
   * @var \Drupal\multiversion\Entity\WorkspaceInterface
   */
  protected $newWorkspace;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array(
    'multiversion',
    'menu_link_content',
    'block',
  );

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->workspaceManager = \Drupal::service('workspace.manager');
    $web_user = $this->drupalCreateUser(array('administer menu', 'administer workspaces'));
    $this->drupalLogin($web_user);
    $this->drupalPlaceBlock('system_menu_block:main');

    $this->initialWorkspace = $this->workspaceManager->getActiveWorkspace();
    $this->newWorkspace = Workspace::create(['machine_name' => 'foo', 'label' => 'Foo', 'type' => 'basic']);
    $this->newWorkspace->save();
  }

  public function testMenuLinksInDifferentWorkspaces() {
    MenuLinkContent::create([
      'menu_name' => 'main',
      'link' => 'route:user.page',
      'title' => 'Pineapple'
    ])->save();

    $this->drupalGet('user/2');
    $this->assertLink('Pineapple');

    $this->drupalPostForm($this->newWorkspace->url('activate-form'), [], 'Activate');
    $this->drupalGet('user/2');
    $this->assertNoLink('Pineapple');

    // The previous page request only changed workspace for the session of the
    // request. We have to switch workspace in the test context as well.
    $this->workspaceManager->setActiveWorkspace($this->newWorkspace);
    // Save another menu link.
    MenuLinkContent::create([
      'menu_name' => 'main',
      'link' => 'route:user.page',
      'title' => 'Pear',
    ])->save();

    $this->drupalGet('user/2');
    $this->assertNoLink('Pineapple');
    $this->assertLink('Pear');

    // Switch back to the default workspace and ensure the menu links render
    // as expected.
    $this->drupalPostForm($this->initialWorkspace->url('activate-form'), [], 'Activate');
    $this->drupalGet('user/2');
    $this->assertLink('Pineapple');
    $this->assertNoLink('Pear');
  }

}

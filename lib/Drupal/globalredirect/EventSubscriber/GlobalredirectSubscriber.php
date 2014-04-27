<?php

/**
 * @file
 * Definition of Drupal\globalredirect\EventSubscriber\GlobalredirectSubscriber.
 */

namespace Drupal\globalredirect\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\AliasManager;
use Drupal\Core\Routing\MatchingRouteNotFoundException;
use Drupal\Core\Url;
use Drupal\globalredirect\RedirectChecker;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;

/**
 * KernelEvents::REQUEST subscriber for redirecting q=path/to/page requests.
 */
class GlobalredirectSubscriber implements EventSubscriberInterface {

  /**
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * @var \Drupal\Core\Path\AliasManager
   */
  protected $aliasManager;

  /**
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHanldler;

  /**
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * @var \Drupal\globalredirect\RedirectChecker
   */
  protected $redirectChecker;

  /**
   * Constructs a \Drupal\redirect\EventSubscriber\RedirectRequestSubscriber object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config.
   * @param \Drupal\Core\Path\AliasManager $alias_manager
   *   The alias manager service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager service.
   * @param \Drupal\globalredirect\RedirectChecker $redirect_checker
   *   The redirect checker service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, AliasManager $alias_manager, LanguageManagerInterface $language_manager, ModuleHandlerInterface $module_handler, EntityManagerInterface $entity_manager, RedirectChecker $redirect_checker) {
    $this->configFactory = $config_factory;
    $this->config = $config_factory->get('globalredirect.settings');
    $this->aliasManager = $alias_manager;
    $this->languageManager = $language_manager;
    $this->moduleHanldler = $module_handler;
    $this->entityManager = $entity_manager;
    $this->redirectChecker = $redirect_checker;
  }

  /**
   * Detects a q=path/to/page style request and performs a redirect.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   *   The Event to process.
   */
  public function globalredirectCleanUrls(GetResponseEvent $event) {
    if (!$this->config->get('nonclean_to_clean')) {
      return;
    }

    $request = $event->getRequest();
    $uri = $request->getUri();
    if (strpos($uri, 'index.php')) {
      $url = str_replace('/index.php', '', $uri);
      $event->setResponse(new RedirectResponse($url, 301));
    }
  }

  /**
   * Detects a url with an ending slash (/) and removes it.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   */
  public function globalredirectDeslash(GetResponseEvent $event) {
    if (!$this->config->get('deslash')) {
      return;
    }

    $request = $event->getRequest();
    $path_info = $request->getPathInfo();
    if (substr($path_info, -1, 1) === '/') {
      $path_info = trim($path_info, '/');
      try {
        $path_info = $this->aliasManager->getSystemPath($path_info);
        $this->setResponse($event, $path_info);
      }
      catch (MatchingRouteNotFoundException $e) {
        // Do nothing here as it is not our responsibility to handle this.
      }
    }
  }

  /**
   * Redirects any path that is set as front page to the site root.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   */
  public function globalredirectFrontPage(GetResponseEvent $event) {
    if (!$this->config->get('frontpage_redirect') || !drupal_is_front_page()) {
      return;
    }
    $request_uri = $event->getRequest()->getPathInfo();
    // url() returns the correct uri for the frontpage with prefixes.
    $correct_front_page_uri = url('<front>');
    if ($correct_front_page_uri != $request_uri) {
      $this->setResponse($event, '<front>');
    }
  }

  /**
   * Normalizes the path aliases.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   */
  public function globalredirectNormalizeAliases(GetResponseEvent $event) {
    // No redirect on:
    // Frontpage, exception handling, request for exception pages (404, 403).
    if (!$this->config->get('normalize_aliases') || drupal_is_front_page() || $event->getRequest()->attributes->has('exception') || $event->getRequest()->query->has('_exception_statuscode')) {
      return;
    }
    // _current_path() returns the current path without language prefix.
    // This because LanguageNegotiationUrl removed it.
    $path = _current_path();
    // System path contains the unaliased path, also no language prefix.
    $system_path = $this->aliasManager->getSystemPath($path);
    // Now we generate the alias for the system path.
    $alias = $this->aliasManager->getPathAlias($system_path, $this->languageManager->getCurrentLanguage()->id);
    // url() adds the language prefix again.
    $alias_with_prefix = url($alias);
    // We need the request uri to check against the alias.
    $request_uri = $event->getRequest()->getPathInfo();
    if ($alias_with_prefix != $request_uri) {
      // The redirect is made without language prefix, it's added later.
      $this->setResponse($event, $system_path);
    }
  }

  /**
   * Redirects forum taxonomy terms to correct forum path.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   */
  public function globalredirectForum(GetResponseEvent $event) {
    $request = $event->getRequest();
    if (!$this->config->get('term_path_handler') || !$this->moduleHanldler->moduleExists('taxonomy') || !preg_match('/taxonomy\/term\/([0-9]+)$/', $request->getUri(), $matches)) {
      return;
    }

    $term = $this->entityManager->getStorage('taxonomy_term')->load($matches[1]);
    if (!empty($term) && $term->url() != $request->getPathInfo()) {
      $system_path = $this->aliasManager->getSystemPath(ltrim($term->url(), '/'));
      $this->setResponse($event, $system_path);
    }
  }

  /**
   * Prior to set the response it check if we can redirect.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   *   The event object.
   * @param string $path
   *   The path where we want to redirect.
   */
  protected function setResponse(GetResponseEvent $event, $path) {
    $request = $event->getRequest();
    // For some path's (like files) there are not routes available, so just stop
    // when this happens.
    try {
      $url = Url::createFromPath($path);
    }
    catch (MatchingRouteNotFoundException $e) {
      return;
    }
    parse_str($request->getQueryString(), $query);
    $url->setOption('query', $query);
    //$url->setAbsolute(TRUE);

    if ($this->redirectChecker->canRedirect($url->getRouteName(), $request)) {
      $headers = array(
        // Not a permanent redirect.
        'Cache-Control' => 'no-cache, must-revalidate, post-check=0, pre-check=0',
      );
      drupal_page_is_cacheable(FALSE);
      $event->setResponse(new RedirectResponse($url->toString(), 301, $headers));
    }
  }

  /**
   * {@inheritdoc}
   */
  static function getSubscribedEvents() {
    // Run earlier than all the listeners in
    // Drupal\Core\EventSubscriber\PathSubscriber, because there is no need
    // to decode the incoming path, resolve language, etc. if the real path
    // information is in the query string.
    $events[KernelEvents::REQUEST][] = array('globalredirectCleanUrls', 200);
    $events[KernelEvents::REQUEST][] = array('globalredirectDeslash', 200);
    $events[KernelEvents::REQUEST][] = array('globalredirectFrontPage', 200);
    $events[KernelEvents::REQUEST][] = array('globalredirectNormalizeAliases', 200);
    $events[KernelEvents::REQUEST][] = array('globalredirectForum', 200);
    return $events;
  }
}

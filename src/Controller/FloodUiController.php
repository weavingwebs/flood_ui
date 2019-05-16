<?php

namespace Drupal\flood_ui\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class FloodUiController.
 */
class FloodUiController extends ControllerBase {

  /**
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a new FloodUiController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   */
  public function __construct(
    Connection $database,
    MessengerInterface $messenger,
    LoggerChannelInterface $logger
  ) {
    $this->database = $database;
    $this->messenger = $messenger;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('messenger'),
      $container->get('flood_ui.logger')
    );
  }

  public function unblock(Request $request): Response {
    $identifier = $request->query->get('identifier');
    $event = $request->query->get('event');

    $query = $this->database->delete('flood');
    $query->condition('identifier', $identifier);
    $query->condition('event', $event);
    $query->condition('expiration', \Drupal::time()->getRequestTime(), '>');
    $count = $query->execute();

    $message = 'Cleared %identifier from %event flood. (%count entries deleted)';
    $context = [
      '%identifier' => $identifier,
      '%event' => $event,
      '%count' => $count,
    ];
    $this->messenger->addStatus($this->t($message, $context));
    $this->logger->info($message, $context);

    return RedirectResponse::create(Url::fromRoute('flood_ui.view')->toString());
  }

  public function viewFlood(): array {
    $query = $this->database->select('flood', 'f');
    $query->fields('f', ['event', 'identifier']);
    $query->addExpression('count(*)', 'count');
    $query->condition('f.expiration', \Drupal::time()->getRequestTime(), '>');
    $query->groupBy('f.identifier');
    $query->groupBy('f.event');
    $results = $query->execute();

    $table = [
      '#type' => 'table',
      '#header' => [
        $this->t('Event'),
        $this->t('Identifier'),
        $this->t('Count'),
        '',
      ],
    ];
    foreach ($results as $row) {
      $table[] = [
        [
          '#markup' => Html::escape($row->event),
        ],
        [
          '#markup' => Html::escape($row->identifier),
        ],
        [
          '#markup' => Html::escape($row->count),
        ],
        [
          '#type' => 'link',
          '#url' => Url::fromRoute('flood_ui.unblock', [
            'identifier' => $row->identifier,
            'event' => $row->event,
          ]),
          '#title' => $this->t('Delete all entries for %ip', [
            '%ip' => $row->identifier,
          ]),
          '#attributes' => [
            'class' => ['button'],
          ],
        ],
      ];
    }

    return [
      'table' => $table,
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

}

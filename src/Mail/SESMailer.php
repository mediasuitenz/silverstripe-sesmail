<?php

namespace Symbiote\SilverStripeSESMailer\Mail;

use Aws\Ses\SesClient;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Injector\Injector;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Bridge\Amazon\Transport\SesTransport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email as SymfonyEmail;

/**
 * A mailer implementation which uses Amazon's Simple Email Service.
 *
 * This mailer uses the SendRawEmail endpoint, so it supports sending attachments. Note that this only sends sending
 * emails to up to 50 recipients at a time, and Amazon's standard SES usage limits apply.
 *
 * Does not support inline images.
 */
class SESMailer implements MailerInterface
{
	/**
	 * @var SesClient
	 */
	protected $client;

	/**
	 * Uses QueuedJobs module when sending emails
	 *
	 * @var boolean
	 */
	protected $useQueuedJobs = true;

	/**
	 * @param array $config
	 */
	public function __construct($config)
	{
		$this->client = SesClient::factory($config);
	}

	/**
	 * @param boolean $bool
	 *
	 * @return $this
	 */
	public function setUseQueuedJobs($bool)
	{
		$this->useQueuedJobs = $bool;

		return $this;
	}

	/**
	 * @param SilverStripe\Control\Email $email
	 */
	public function send($email): void
	{
		$destinations = $email->getTo();

		if ($overideTo = Email::getSendAllEmailsTo()) {
			$destinations = $overideTo;
		} else {
			if ($cc = $email->getCc()) {
				$destinations = array_merge($destinations, $cc);
			}

			if ($bcc = $email->getBcc()) {
				$destinations = array_merge($destinations, $bcc);
			}

			if ($addCc = Email::getCCAllEmailsTo()) {
				$destinations = array_merge($destinations, $addCc);
			}

			if ($addBCc = Email::getBCCAllEmailsTo()) {
				$destinations = array_merge($destinations, $addBCc);
			}
		}

		$subject = $email->getSubject();
		$message = $email->getBody();

		if (class_exists(QueuedJobService::class) && $this->useQueuedJobs) {
			$job = Injector::inst()->createWithArgs(SESQueuedMail::class, array(
				$destinations,
				$subject,
				$message
			));

			singleton(QueuedJobService::class)->queueJob($job);

			return;
		}

		try {
			$this->sendSESClient($destinations, $subject, $message);
		} catch (\Aws\Ses\Exception\SesException $ex) {
			Injector::inst()->get(LoggerInterface::class)->warning($ex->getMessage());
		}
	}

	/**
	 * Send an email via SES. Expects an array of valid emails and a raw email body that is valid.
	 *
	 * @param array $destinations array of emails addresses this email will be sent to
	 * @param string $subject Email subject
	 * @param string $message Email message body
	 */
	protected function sendSESClient($destinations, $subject, $message)
	{
		$transport = new SesTransport($this->client);
		$mailer = new Mailer($transport);

		$symfonyEmail = (new SymfonyEmail())
			->from(Email::config()->get('admin_email'))
			->to(...$destinations)
			->subject($subject)
			->text($message);

		$mailer->send($symfonyEmail);
	}
}

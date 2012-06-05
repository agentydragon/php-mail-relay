<?php

require_once('Mail.php');

use \Prvak\Mail\Fetcher,
	\Prvak\Mail\AbstractMail,
	\Prvak\Mail\FetcherMailVisitor;

// This example reads incoming mail from a Gmail account through IMAP
// and relays every new mail to $target.

$hostname = '{imap.gmail.com:993/imap/ssl}INBOX';
$username = '*****@*****.***';
$password = '***';
$target = '*****@*****.***';

$fetcher = new Fetcher($hostname, $username, $password);

class MyVisitor implements FetcherMailVisitor {
	public function handleMail(AbstractMail $mail) {
		$to = \Prvak\Mail\ToFieldParser::getAlias($mail->getTo());
		if (is_array($to)) $to = implode(";", $to);

		print("Mail to: " . $to . "\n");

		// print("Body: " . $mail->getRawBody());
		// print("Headers: " . $mail->getRawHeader() . "\n");
		
		$mail->setSeen(true);

		if (strstr($to, "noreply") !== FALSE) {
			if (strstr($to, "send") !== FALSE) {
				$headers = \Prvak\Mail\HeaderStripper::stripHeaders($mail->getRawHeader(), array(
					"To", "Subject"
				));

				print("Stripped headers: " . $headers . "\n");

				mail($target, "Intercepted: " . $mail->getSubject(),
					$mail->getRawBody(),
					$headers);

				print("Mail sent.\n");
			}
			print("Deleting.\n");
			$mail->delete();
		}
	}
};

print("Fetching mail...\n");
$fetcher->fetch(new MyVisitor(), Fetcher::FILTER_UNSEEN);
print("Done.\n");

<?php

/**
 * @author	Michal Pokorný	pok@rny.cz
 */

namespace Prvak\Mail;

use \Exception;

/**
 * Thrown when IMAP or POP3 connections fail.
 */
class ConnectionFailedException extends Exception {};

/**
 * Thrown when an IMAP or POP3 connection isn't open when it should be.
 */
class NotConnectedException extends Exception {};

/**
 * An IMAP (or possibly POP3) client.
 */
class Client {
	const FILTER_ALL = 'ALL';
	const FILTER_UNSEEN = 'UNSEEN';

	const FLAG_SEEN = '\Seen';

	/**
	 * Stores the hostname for imap_open.
	 * @var	string $hostname
	 */
	private $hostname;

	/**
	 * Stores the username for imap_open
	 * @var	string $username
	 */
	private $username;

	/**
	 * Stores the password for imap_open
	 * @var	string $password
	 */
	private $password;

	/**
	 * Constructs the Client, given credentials.
	 * @param	string	$hostname	The hostname passed to imap_open
	 * @param	string	$username	The username to use
	 * @param	string	$password	The password to use
	 */
	public function __construct($hostname, $username, $password) {
		$this->hostname = $hostname;
		$this->username = $username;
		$this->password = $password;
	}

	/**
	 * @var	resource	The IMAP stream. FALSE if not open.
	 */
	private $inbox = FALSE;

	/**
	 * Opens the connection.
	 * @return	void
	 */
	public function open() {
		$this->inbox = imap_open($this->hostname, $this->username, $this->password);

		if ($this->inbox === FALSE) {
			$this->inbox = NULL;
			throw new ConnectionFailedException();
		}
	}

	/**
	 * Throws a NotConnectedException if the connection isn't open.
	 */
	private function checkConnected() {
		if ($this->inbox === FALSE) {
			throw new NotConnectedException();
		}
	}

	/**
	 * Fetches the IDs of all inbox messages. Uses an optional filter.
	 * @param	string	$filter	The filter to use. One of Client::FILTER_*
	 * @return	mixed[]
	 */
	public function fetchInboxIDs($filter = Client::FILTER_ALL) {
		$this->checkConnected();

		$ids = imap_search($this->inbox, $filter);
		if ($ids === FALSE) { // empty result is indicated as FALSE.
			$ids = array();
		}

		return $ids;
	}

	/**
	 * Wraps around imap_fetch_overview.
	 * @param	mixed	$id	The message ID
	 */
	public function fetchMessageOverview($id) {
		$this->checkConnected();

		$overview = imap_fetch_overview($this->inbox, $id);
		return $overview[0];
	}

	/**
	 * Deletes the message with the given ID.
	 * @param	mixed	$id	The message ID
	 */
	public function delete($id) {
		$this->checkConnected();

		imap_delete($this->inbox, $id);	
	}

	/**
	 * Permanently expunges all deleted messages.
	 * @return	void
	 */
	public function expunge() {
		$this->checkConnected();

		imap_expunge($this->inbox);
	}

	/**
	 * Closes the connection.
	 * @return	void
	 */
	public function close() {
		imap_close($this->inbox);
		$this->inbox = FALSE;
	}

	/**
	 * Fetches the raw body of the message with the given ID.
	 * @param	mixed	$id	The message ID
	 * @return	string
	 */
	public function getRawBody($id) {
		$this->checkConnected();

		return imap_body($this->inbox, $id);
	}

	/**
	 * Fetches the raw headers of the message with the given ID.
	 * @param	mixed	$id	The message ID
	 * @return	string
	 */
	public function getRawHeader($id) {
		$this->checkConnected();

		return imap_fetchheader($this->inbox, $id);
	}

	/**
	 * Sets a flag on a message on or off.
	 * @param	mixed	$id		The message ID
	 * @param	string	$flag	The flag (one of Client::FLAG_*)
	 * @param	boolean	$on		Set the flag on?
	 */
	public function setMessageFlag($id, $flag, $on = true) {
		$this->checkConnected();

		if ($on) {
			imap_setflag_full($this->inbox, $id, $flag);
		} else {
			imap_clearflag_full($this->inbox, $id, $flag);
		}
	}
};

/**
 * Represents an immutable e-mail message stored on a server.
 */
interface AbstractMail {
	/**
	 * Returns the decoded sender header.
	 * @return	string
	 */
	public function getFrom();

	/**
	 * Marks the mail for deletion. Won't really be deleted until purged.
	 */
	public function delete();

	/**
	 * Returns the decoded To header.
	 * @return	string
	 */
	public function getTo();

	/**
	 * Returns the decoded subject.
	 * @return	string
	 */
	public function getSubject();

	/**
	 * Returns the raw body of the mail.
	 * @return	string
	 */
	public function getRawBody();

	/**
	 * Returns the raw headers of the mail.
	 * @return	string
	 */
	public function getRawHeader();

	/**
	 * Sets the "Seen" flag of the mail.
	 * @param	boolean	$on	Set the flag on?
	 * @return void
	 */
	public function setSeen($on = true);
};

/**
 * A generic unconstructable class.
 */
abstract class Unconstructable {
	/**
	 * The constructor is private to disallow construction.
	 */
	protected function __construct() {}
};

/**
 * Parses To fields in components (currently the mailbox only).
 */
class ToFieldParser extends Unconstructable {
	/**
	 * Parses the mailbox(es) from a To: field.
	 * @param	string	$toField	The To: field to parse.
	 * @return	mixed
	 * @static
	 */
	public static function getAlias($toField) {
		$addresses = imap_rfc822_parse_adrlist($toField, "");
		$result = array();
		foreach ($addresses as $id => $val) {
			$result[] = $val->mailbox;
		}
		if (count($result) == 1) return $result[0];
		return $result;
	}
};

/**
 * This class strips unwanted headers from raw header strings.
 */
class HeaderStripper extends Unconstructable {
	/**
	 * Strips away some unwanted headers from the raw header text given.
	 * @param string	$rawHeaders	The raw headers.
	 * @param array		$strip		An array of headers to strip.
	 * @return void
	 * @static
	 */
	public static function stripHeaders(string $rawHeaders, $strip) {
		$result = "";
		$lines = preg_split("/(\r?\n|\r)/", $rawHeaders);

		foreach ($lines as $line) {
			if (preg_match('/^[A-Za-z]/', $line) &&
				preg_match('/([^:]+): ?(.*)$/', $line, $matches) &&
				in_array($matches[1], $strip)) {
				continue;
			} else {
				$result .= $line . "\r\n";
			}
		}
		return $result;
	}
};

/**
 * Decodes encoded mail fields (like To: and From: headers).
 */
class MimeDecoder extends Unconstructable {
	/**
	 * Decodes an encoded mail string (like a From: header).
	 * @param	string	$string	The field to decode
	 * @return	string
	 * @static
	 */
	public static function decode($string) {
		return utf8_encode(iconv_mime_decode($string));
	}
};

/**
 * A mail obtained from Client.
 */
class Mail implements AbstractMail {
	private $client;
	private $id;

	private $from;
	private $to;
	private $subject;

	/**
	 * Constructs the Mail, given Client and message ID.
	 * @param	Prvak\Mail\Client	$client	The client that provides the mail
	 * @param	mixed	$id		The message ID
	 */
	public function __construct(Client $client, $id) {
		$this->client = $client;
		$this->id = $id;

		$overview = $client->fetchMessageOverview($this->id);
		$this->from = MimeDecoder::decode($overview->from);
		$this->to = MimeDecoder::decode($overview->to);
		$this->subject = MimeDecoder::decode($overview->subject);
	}

	public function getFrom() {
		return $this->from;
	}

	public function delete() {
		$this->client->delete($this->id);
	}

	public function getTo() {
		return $this->to;
	}

	public function getSubject() {
		return $this->subject;
	}

	public function getRawBody() {
		return $this->client->getRawBody($this->id);
	}

	public function getRawHeader() {
		return $this->client->getRawHeader($this->id);
	}

	/**
	 * Sets or unsets the "Seen" flag of the mail.
	 * @param	boolean	$on	Set the flag on?
	 * @return void
	 */
	public function setSeen($on = true) {
		$this->client->setMessageFlag($this->id, Client::FLAG_SEEN, true);
	}
};

interface FetcherMailVisitor {
	/**
	 * Should handle the given AbstractMail. Called by Fetcher on every mail in the inbox.
	 * @param	Prvak\Mail\AbstractMail	$mail	The mail to process
	 */
	public function handleMail(AbstractMail $mail);
};

/**
 * Handles every mail in a mailbox. A wrapper around Client for convenient use.
 */
class Fetcher {
	const FILTER_ALL = Client::FILTER_ALL;
	const FILTER_UNSEEN = Client::FILTER_UNSEEN;

	/**
	 * The connected client.
	 * @var		Prvak\Mail\Client	$client
	 */
	private $client;

	/**
	 * Constructs the Fetcher.
	 * @param	string	$hostname	The hostname to pass to the Client constructor
	 * @param	string	$username	The username to username
	 * @param	string	$password	The password to use
	 */
	public function __construct($hostname, $username, $password) {
		$this->client = new Client($hostname, $username, $password);
	}

	/**
	 * Fetches mail from the mailbox and invokes the visitor on every mail.
	 * @param	FetcherMailVisitor	$visitor	The visitor to invoke.
	 * @param	string				$filter		The filter to use. One of Client::FILTER_*
	 * @return	void
	 */
	public function fetch(FetcherMailVisitor $visitor, $filter = Client::FILTER_ALL) {
		$client = $this->client;
		$client->open();
		$ids = $client->fetchInboxIDs($filter);

		foreach ($ids as $key => $id) {
			$visitor->handleMail(new Mail($client, $id));
		}

		$client->expunge();
		$client->close();
	}
};

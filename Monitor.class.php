<?php
date_default_timezone_set("UTC");
class Monitor {
	/** Indicates no problems. */
	public static $EXIT_SUCCESS = 0;

	/** Indicates worst problem to occur was a warning status. */
	public static $EXIT_WARNING = 1;

	/** Indicates worst problem to occur was a critical status. */
	public static $EXIT_CRITICAL = 2;

	/** Text corresponding to each exit status. */
	private static $EXIT_TEXT = array(
		0 => 'Success',
		1 => 'Warning',
		2 => 'Critical'
	);

	private $configuration = null;
	private $statuses = null;
	private $worst = null;
	
	/**
	 * @param ini_file
	 *      Fully-qualified path to an INI configuration file for the monitor.
	 */
	public function __construct ($ini_file) {
		$this->statuses = array();
		$this->configuration = parse_ini_file($ini_file, true);
	}

	/**
	 * Runs all the configured checks.
	 */
	public function runChecks () {
		if ($this->configuration != null) {
			$checks = explode(',', $this->configuration['checks']);
			foreach ($checks as $check) {
				$info = $this->configuration[trim($check)];
				if ($info['type'] == 'running') {
					$this->checkRunning($info['script']);
				} else if ($info['type'] == 'file') {
					$this->checkFile($info['file'], $info['warning'],
							$info['critical']);
				} else if ($info['type'] == 'index') {
					$this->checkIndex(new PDO($info['dsn'], $info['username'],
							$info['password']), $info['warning'], $info['critical']);
				} else if ($info['type'] == 'heartbeat') {
					$this->checkHeartbeat($info['file'],
							$this->configuration[$info['file_age']],
							$this->configuration[$info['product_age']],
							$this->configuration[$info['memory_usage']]);
				} else {
					// Unknown check. Is this an error?
					// TODO
				}
			}
		} else {
			// No configuration. Is this an error?
			// TODO
		}
	}

	/**
	 * @param format
	 *      The format in which to output the summary information. Default is to
	 *      use text/plain. Initial implementation only supports this default
	 *      value.
	 *
	 * @return {String}
	 *      A single line of summary information about the results of the checks.
	 *      Output from this method will be sent in the Nagios email.
	 */
	public function getOutputSummary ($format = 'text/plain') {
		if ($this->worst != null) {
			return $this->worst['message'] . "\n";
		} else {
			return "PDL is okay.\n";
		}
	}

	/**
	 * @param format
	 *      The format in which to output the summary detailed information.
	 *      Default is to use text/plain. Initial implementation only supports
	 *      this default value.
	 *
	 * @return {String}
	 *      A (possibly) multi-line output of detailed information about the
	 *      results of the checks.
	 */
	public function getOutputDetails ($format = 'text/plain') {
		$buffer = '';

		$buffer .= 'Host = ' . `hostname`;
		$buffer .= 'Date = ' . date('Y-m-d H:i:s') . "\n\n";

		foreach ($this->statuses as $status) {
			$buffer .= '[' . self::$EXIT_TEXT[$status['status']] . '] ' .
					$status['message'] . "\n";
		}

		return $buffer;
	}

	/**
	 * @return {Integer}
	 *      EXIT_SUCCESS if no problems were found in the checks.
	 *      EXIT_WARNING if, at worst, a warning occurred.
	 *      EXIT_CRITICAL if, at worse, a critical problem occurred.
	 */
	public function getReturnStatus () {
		if ($this->worst != null) {
			return $this->worst['status'];
		} else {
			return self::$EXIT_SUCCESS;
		}
	}

	/**
	 * Adds the status to the list of statuses. If the status exit value exceeds,
	 * or is more recent than the current "worst status", this status should
	 * replace the current "worst status".
	 *
	 * @param status
	 *      The status to add
	 */
	protected function addStatus ($status) {
		if ($status['status'] > self::$EXIT_SUCCESS &&
			(
				// No worst status yet
				$this->worst == null ||

				// This status is worse
				$status['status'] > $this->worst['status'] ||

				// This status is equally bad, but more recent.
				(
					$status['status'] == $this->worst['status'] &&
					$status['updated'] > $this->worst['updated']
				
				)
			)
		) {
			$this->worst = $status;
		}

		$this->statuses[] = $status;
	}

	/**
	 * Uses the given script to check if PDL is running.
	 *
	 * @param script
	 *      The fully-qualified path to the init script for the PDL client.
	 *      This script should accept the "status" argument and should return
	 *         0 => PDL is running
	 *         1 => PDL is NOT running
	 */
	protected function checkRunning ($script) {
		$command = $script . ' status';
		$message = exec($command, $output, $status);

		if ($status == 0) {
			$status = self::$EXIT_SUCCESS;
		} else {
			$status = self::$EXIT_CRITICAL;
		}

		$this->addStatus(array(
			'message' => $message,
			'status' => $status,
			'updated' => time()
		));
	}

	/**
	 * Checks file update time. Compares the age to the warning and critical
	 * thresholds. If the age of file exceeds either threshold, an appropriate
	 * status is added. Otherwise an "OKAY" status is added. 
	 *
	 * @param file
	 *      The file whose age is to be checked.
	 * @param warning
	 *      The maximum age of the file before a warning status is triggered.
	 * @param critical
	 *      The maximum age of the file before a critical status is triggered.
	 */
	protected function checkFile ($file, $warning, $critical) {
		$now = time();

		if (!file_exists($file)) {
			// File does not exist. This is bad.
			$this->addStatus(array(
				'message' => 'No such file or directory [' . $file . ']',
				'status' => self::$EXIT_CRITICAL,
				'updated' => $now
			));
		} else {
			$mtime = filemtime($file);
			$age = $now - $mtime;
			if ($age > $critical) {
				$message = 'File [' . $file . '] age [' . $age . ' seconds] ' .
						'exceeds critical threshold. [' . $critical . ' seconds].';

				$this->addStatus(array(
					'message' => $message,
					'status' => self::$EXIT_CRITICAL,
					'updated' => $now
				));
			} else if ($age > $warning) {
				$message = 'File [' . $file . '] age [' . $age . ' seconds] ' .
						'exceeds warning threshold. [' . $warning . ' seconds].';

				$this->addStatus(array(
					'message' => $message,
					'status' => self::$EXIT_WARNING,
					'updated' => $now
				));
			} else {
				$message = 'File [' . $file . '] age [' . $age . 
						' seconds] is okay.';

				$this->addStatus(array(
					'message' => $message,
					'status' => self::$EXIT_SUCCESS,
					'updated' => $now
				));
			}
		}
	}

	/**
	 * Checks the index update time age against the given warning and critical
	 * thresholds.
	 *
	 * @param pdo
	 *      A database connection to an index.
	 * @param warning
	 *      The maximum age of the index before a warning status is triggered.
	 * @param critical
	 *      The maximum age of the index before a critical status is triggered.
	 */
	protected function checkIndex($pdo, $warning, $critical) {
		$now = time();
		$query = '
			SELECT
				MAX(created) AS lastEventAdd,
				MAX(updated) AS lastEventUpdate
			FROM
				event
		';

		$result = $pdo->query($query);
		$info = $result->fetch(PDO::FETCH_ASSOC);
		
		$addAge = $now - intval(substr($info['lastEventAdd'], 0, -3));
		$updateAge = $now - intval(substr($info['lastEventUpdate'], 0, -3));
		$age = min($addAge, $updateAge);

		if ($age > $critical) {
			$message = 'Index age [' . $age . ' seconds] exceeds critical ' .
					'threshold [' . $critical . ' seconds].';

			$this->addStatus(array(
				'message' => $message,
				'status' => self::$EXIT_CRITICAL,
				'updated' => $now
			));
		} else if ($age > $warning) {
			$message = 'Index age [' . $age . ' seconds] exceeds warning ' .
					'threshold [' . $warning . ' seconds].';

			$this->addStatus(array(
				'message' => $message,
				'status' => self::$EXIT_WARNING,
				'updated' => $now
			));
		} else {
			$message = 'Index age [' . $age . ' seconds] is okay.';

			$this->addStatus(array(
				'message' => $message,
				'status' => self::$EXIT_SUCCESS,
				'updated' => $now
			));
		}
	}

	/**
	 * Checks the heartbeat information file for various (configurable) statuses.
	 *
	 * @param file
	 *      The heartbeat file to check.
	 * @param file_age
	 *      Associative array contaning the warning and critical thresholds for
	 *      a file check.
	 * @param product_age
	 *      Associative array containing the warning and critical thresholds for
	 *      a product check.
	 * @param memory_usage
	 *      Associative array containing the warning and critical thresholds for
	 *      a memory check.
	 */
	protected function checkHeartbeat($file, $file_age = null,
			$product_age = null, $memory_usage = null) {

		$now = time();

		// Parse the heartbeat JSON as an array. Suppress warnings about file not
		// existing. The checkFile will do that for us and if the file does not
		// exist, $heartbeat will be set to null.
		$heartbeat = json_decode(@file_get_contents($file), true);

		if ($file_age != null) {
			// Note :: Using filemtime as proxy for heartbeat arrival. Both time
			// values should be within epsilon of each other so it shouldn't
			// matter how we check it.
			$this->checkFile($file, $file_age['warning'], $file_age['critical']);
		}

		if ($product_age != null) {
			if ($heartbeat != null && isset($heartbeat[$product_age['name']])) {

				$product = $heartbeat[$product_age['name']]['indexed product'];

				$this->checkIndexedProduct($product, $product_age['warning'],
						$product_age['critical']);
			} else {
				// No heartbeat data. Check can not be performed. Should this be an
				// error?
				// TODO
			}
		}

		if ($memory_usage != null) {
			if ($heartbeat != null && isset($heartbeat['heartbeat'])) {

				$memory = $heartbeat['heartbeat']['totalUsed'];

				$this->checkMemoryUsage($memory, $memory_usage['warning'],
						$memory_usage['critical']);
			} else {
				// No heartbeat data. Check can not be performed. Should this be an
				// error?
				// TODO
			}
		}
	}

	protected function checkIndexedProduct ($product, $warning, $critical) {
		$now = time();
		$updated = intval(substr($product['date'], 0, -3));
		$age = $now - $updated;

		if ($age > $critical) {
			$message = 'Indexed product [' . $product['message'] . '] age [' .
					$age . ' seconds] exceeds critical threshold. [' . $critical .
					' seconds].';

			$this->addStatus(array(
				'message' => $message,
				'status' => self::$EXIT_CRITICAL,
				'updated' => $updated
			));
		} else if ($age > $warning) {
			$message = 'Indexed product [' . $product['message'] . '] age [' .
					$age . ' seconds] exceeds warning threshold. [' . $warning . 
					' seconds].';

			$this->addStatus(array(
				'message' => $message,
				'status' => self::$EXIT_WARNING,
				'updated' => $updated
			));
		} else {
			$message = 'Indexed product [' . $product['message'] . '] age [' .
					$age . ' seconds] is okay.';

			$this->addStatus(array(
				'message' => $message,
				'status' => self::$EXIT_SUCCESS,
				'updated' => $updated
			));
		}
	}

	protected function checkMemoryUsage ($memory, $warning, $critical) {
		// Should be okay to assume an integer. If memory > ~ 4 billion, this is
		// probably not good anyway.
		$committed = intval($memory['message']); 

		if ($committed > $critical) {
			$message = 'Total used memory [' . $committed . ' bytes] ' .
					'exceeds critical threshold [' . $critical . ' bytes].';

			$this->addStatus(array(
				'message' => $message,
				'status' => self::$EXIT_CRITICAL,
				'updated' => time()
			));
		} else if ($committed > $warning) {
			$message = 'Total used memory [' . $committed . ' bytes] ' .
					'exceeds warning threshold [' . $warning . ' bytes].';

			$this->addStatus(array(
				'message' => $message,
				'status' => self::$EXIT_WARNING,
				'updated' => time()
			));
		} else {
			$message = 'Total used memory [' . $committed . ' bytes] okay.';

			$this->addStatus(array(
				'message' => $message,
				'status' => self::$EXIT_SUCCESS,
				'updated' => time()
			));
		}
	}
}

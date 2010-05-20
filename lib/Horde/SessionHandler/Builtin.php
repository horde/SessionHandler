<?php
/**
 * SessionHandler:: implementation for PHP's built-in session handler.
 * This doesn't do any session handling itself - instead, it exists to allow
 * utility features to be used with the built-in PHP handler.
 *
 * Copyright 2005-2010 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @author   Matt Selsky <selsky@columbia.edu>
 * @category Horde
 * @package  SessionHandler
 */
class Horde_SessionHandler_Builtin extends Horde_SessionHandler_Driver
{
    /**
     * Constructor.
     *
     * @param array $params  Parameters.
     */
    public function __construct(array $params = array())
    {
        parent::__construct(array_merge($params, array(
            'noset' => true
        )));
    }

    /**
     * Open the backend.
     *
     * @param string $save_path     The path to the session object.
     * @param string $session_name  The name of the session.
     */
     protected function _open($save_path = null, $session_name = null)
     {
     }

     /**
      * Close the backend.
      *
      * @throws Horde_Exception
      */
    protected function _close()
    {
    }

    /**
     * Read the data for a particular session identifier from the backend.
     *
     * @param string $id  The session identifier.
     *
     * @return string  The session data.
     */
    protected function _read($id)
    {
        $file = session_save_path() . DIRECTORY_SEPARATOR . 'sess_' . $id;
        $session_data = @file_get_contents($file);
        if (($session_data === false) && $this->_logger) {
            $this->_logger->log('Unable to read file: ' . $file, 'ERR');
        }

        return strval($session_data);
    }

    /**
     * Write session data to the backend.
     *
     * @param string $id            The session identifier.
     * @param string $session_data  The session data.
     *
     * @return boolean  True on success, false otherwise.
     */
    protected function _write($id, $session_data)
    {
        return false;
    }

    /**
     * Destroy the data for a particular session identifier in the backend.
     * This method should only be called internally by PHP via
     * session_set_save_handler().
     *
     * @param string $id  The session identifier.
     *
     * @return boolean  True on success, false otherwise.
     */
    public function destroy($id)
    {
        return false;
    }

    /**
     * Garbage collect stale sessions from the backend.
     * This method should only be called internally by PHP via
     * session_set_save_handler().
     *
     * @param integer $maxlifetime  The maximum age of a session.
     *
     * @return boolean  True on success, false otherwise.
     */
    public function gc($maxlifetime = 300)
    {
        return false;
    }

    /**
     * Get a list of the valid session identifiers.
     *
     * @return array  A list of valid session identifiers.
     */
    public function getSessionIDs()
    {
        $sessions = array();

        $path = session_save_path();

        try {
            $di = new DirectoryIterator(empty($path) ? Horde_Util::getTempDir() : $path);
        } catch (UnexpectedValueException $e) {
            return $sessions;
        }

        foreach ($di as $key => $val) {
            /* Make sure we're dealing with files that start with sess_. */
            if ($val->isFile() && (strpos($key, 'sess_') === 0)) {
                $sessions[] = substr($entry, strlen('sess_'));
            }
        }

        return $sessions;
    }

}
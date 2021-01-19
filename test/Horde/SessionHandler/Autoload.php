<?php
session_cache_limiter('');
ini_set('session.use_cookies', 0);
Horde_SessionHandler_Storage_BuiltinTest::$dir = Horde_Util::createTempDir();
ini_set('session.save_path', Horde_SessionHandler_Storage_BuiltinTest::$dir);
Horde_SessionHandler_Storage_FileTest::$dir = Horde_Util::createTempDir();
Horde_SessionHandler_Storage_StackTest::$dir = Horde_Util::createTempDir();

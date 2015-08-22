<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Cache;

define( 'DBX_DIR', USER_DIR . 'plugins/dropbox/' );
define( 'DBX_TMP_DIR', USER_DIR . 'plugins/dropbox/.temp/' );
define( 'CURSOR_FILE', DBX_DIR . '.cursor' );
define( 'SYNCPATHS_FILE', DBX_DIR . '.syncpaths');
define( 'RUNNING_FILE', DBX_DIR . '.running');

require_once DBX_DIR . 'vendor/autoload.php';

use Dropbox as dbx;

class DropboxPlugin extends Plugin
{
    private $dbxClient;
    private $cache;

    public static function getSubscribedEvents ()
    {
        return ['onPluginsInitialized' => ['onPluginsInitialized', 0]];
    }

    public function onPluginsInitialized ()
    {
        if ( $this->isAdmin() ) {
            $this->active = false;
            return;
        }

        $path = $this->grav['uri']->path();
        $route = $this->config->get('plugins.dropbox.route');

        if ($route && $route == $path) {
            $this->enable([
                'onPageInitialized' => ['onPageInitialized', 0],
            ]);
        }
    }

    public function onPageInitialized ()
    {
        $this->grav['log']->warning('Dropbox Initialized');

        define( 'CACHE_ENABLED', $this->config->get('system.cache.enabled') );
        if ( CACHE_ENABLED === true ) {
            $this->cache = $this->grav['cache'];
        }

        define( 'DBX_APP_KEY', $this->config->get('plugins.dropbox.app.key') );
        define( 'DBX_APP_SECRET', $this->config->get('plugins.dropbox.app.secret') );
        define( 'DBX_APP_TOKEN', $this->config->get('plugins.dropbox.app.token') );

        define( 'DBX_SYNC_REMOTE', $this->config->get('plugins.dropbox.sync.remote') );
        define( 'DBX_SYNC_LOCAL', DBX_DIR . $this->config->get('plugins.dropbox.sync.local') );

        $request = getenv('REQUEST_METHOD');
        if ( $request === null ) {
            $this->grav['page']->header()->http_response_code = 404;
        } else {
            switch ( $request ) {
                case 'GET':
                    // Challenges and authentication
                    if ( isset( $_GET['challenge'] ) && !empty( $_GET['challenge'] ) ) {
                        echo $_GET['challenge'];
                        exit;
                    }
                    // Manually check for changed files
                    elseif ( in_array( getenv('REMOTE_ADDR'), array( "127.0.0.1", "::1" ) ) ) {
                        if ( $this->getClient() !== false ) {
                            $this->youreMine();
                            $this->grav['page']->header()->http_response_code = 200;
                        }
                    }
                    // 404 for non-local clients that aren't challenging
                    else {
                        $this->grav['page']->header()->http_response_code = 404;
                    }
                    break;
                case 'POST':
                    // Get notifications for changed files
                    if ( $this->signedSealed() === true && $this->getClient() === true ){
                            $this->delivered();
                            $this->grav['page']->header()->http_response_code = 200;
                    }
                    // 403 for unauthorized posts
                    else {
                        $this->grav['page']->header()->http_response_code = 403;
                    }
                    break;
            }
        }
    }

    // Check if post is made by Dropbox
    private function signedSealed ()
    {
        $signature = getenv('HTTP_X_DROPBOX_SIGNATURE');
        if ( $signature === null || empty( $signature ) ){
            return false;
        } else {
            $httpbody = file_get_contents('php://input');
            $hmac = hash_hmac( 'sha256', $httpbody, DBX_APP_SECRET );
            if ( dbx\Security::stringEquals( $signature, $hmac ) ) {
                return true;
            } else {
                return false;
            }
        }
    }

    // Check Dropbox notification for changed files based on hash
    private function delivered ()
    {
        $cursor = null;
        $has_more = true;
        $contentsDelete = array();
        $contentsDirs = array();
        $contentsFiles = array();
        if ( CACHE_ENABLED === true ) {
            $dbx_cache_id = md5( "dbxCursor" . DBX_SYNC_REMOTE );
            list( $cursor ) = $this->cache->fetch( $dbx_cache_id );
            while ( $has_more ) {
                $delta = $this->dbxClient->getDelta( $cursor, DBX_SYNC_REMOTE );
                foreach ( $delta['entries'] as $entry ) {
                    if ( $entry[1] === null ) {
                        $contentsDelete[] = array( $entry[0], null, null, null, null );
                    } else {
                        $contents =
                            array(
                                $entry[0], // Dropbox entry
                                $entry[1]['path'], // CaSe SENSITIVE path
                                strtotime( $entry[1]['modified'] ), // Unix time of modifcation
                                $entry[1]['is_dir'], // If this is a directory
                                $entry[1]['rev'] // Dropbox revision code
                            );
                        if ( $contents[3] === true ) {
                            $contentsDirs[] = $contents;
                        } else {
                            $contentsFiles[] = $contents;
                        }
                    }
                }
                usort( $contentsDelete, array( $this, 'sortCaseSensitvePath' ) );
                usort( $contentsDirs, array( $this, 'sortCaseSensitvePath' ) );
                usort( $contentsFiles, array( $this, 'sortCaseSensitvePath' ) );
                $this->imYours( $contentsDelete );
                $this->imYours( $contentsDirs );
                $this->imYours( $contentsFiles );
                $cursor = $delta['cursor'];
                $this->cache->save( $dbx_cache_id, array( $cursor ) );
                $has_more = $delta['has_more'];
            }
        } else {
            if ( file_exists( CURSOR_FILE ) && file_get_contents( CURSOR_FILE ) !== '' ) {
                $cursor = file_get_contents( CURSOR_FILE );
                while ( $has_more ) {
                    $delta = $this->dbxClient->getDelta( $cursor, DBX_SYNC_REMOTE );
                    foreach ( $delta['entries'] as $entry ) {
                        if ( $entry[1] === null ) {
                            $contentsDelete[] = array( $entry[0], null, null, null, null );
                        } else {
                            $contents =
                                array(
                                    $entry[0], // Dropbox entry
                                    $entry[1]['path'], // CaSe SENSITIVE path
                                    strtotime( $entry[1]['modified'] ), // Unix time of modifcation
                                    $entry[1]['is_dir'], // If this is a directory
                                    $entry[1]['rev'] // Dropbox revision code
                                );
                            if ( $contents[3] === true ) {
                                $contentsDirs[] = $contents;
                            } else {
                                $contentsFiles[] = $contents;
                            }
                        }
                    }
                    usort( $contentsDelete, array( $this, 'sortCaseSensitvePath' ) );
                    usort( $contentsDirs, array( $this, 'sortCaseSensitvePath' ) );
                    usort( $contentsFiles, array( $this, 'sortCaseSensitvePath' ) );
                    $this->imYours( $contentsDelete );
                    $this->imYours( $contentsDirs );
                    $this->imYours( $contentsFiles );
                    $cursor = $delta['cursor'];
                    $this->createLocal( "file", CURSOR_FILE, "put", $cursor );
                    $has_more = $delta['has_more'];
                }
            }
        }
    }

    // Check cache for changed files and download
    private function imYours ( $contents )
    {
        if ( CACHE_ENABLED === true ) {
            foreach ( $contents as $content ){
                $dbx_cache_id = md5( "dbxContent" . $content[0] );
                list( $object, $path , $mtime ) = $this->cache->fetch( $dbx_cache_id );
                if ( $content[1] !== null ) {
                    if ( $object === null || $object !== null && $mtime < $content[2] ) {
                        $content[1] = $this->getCaseSensitivePath( $content[1] );
                        $content[2] = $this->getFile( $content );
                        if ( $content[2] !== false ) { // Don't cache timed out files
                            $this->cache->save( $dbx_cache_id, $content );
                        }
                    }
                } else {
                    $this->deleteLocalFile( $path );
                    if ( $this->cache->fetch( $dbx_cache_id ) !== null ){
                        $this->cache->save( $dbx_cache_id, array( $content[0], null, null, null, null ) );
                    }
                }
            }
        } else {
            foreach ( $contents as $content ){
                if ( file_exists( SYNCPATHS_FILE ) ) {
                    $localSyncPaths = json_decode ( file_get_contents( SYNCPATHS_FILE ) );
                }
                if ( $content[1] !== null ) {
                    $this->getFile( $content );
                    $localSyncPaths[] = $content;
                    $this->createLocal( "file", SYNCPATHS_FILE, "put", json_encode( $localSyncPaths ) );
                } else {
                    foreach ( $localSyncPaths as $index => $localSyncPath ) {
                        if ( $content[0] === $localSyncPath[0] ) {
                            $this->deleteLocalFile( $localSyncPath[1] );
                            unset( $localSyncPaths[ $index ] );
                        }
                        $this->createLocal( "file", SYNCPATHS_FILE, "put", json_encode( $localSyncPaths ) );
                    }
                }
            }
        }
        $this->youreMine();
    }

    // Check cache for changed files and upload
    private function youreMine ()
    {
        if ( CACHE_ENABLED === true ) {
            $dbx_cache_id_running = md5( "dbxRunning" );
            $status = $this->cache->fetch( $dbx_cache_id_running );
            if ( $status === "DONE" || $status === false ) {
                $running = false;
                $this->cache->save( $dbx_cache_id_running, "STARTED" );
            } else {
                $running = true;
            }
        } else {
            if( file_exists( RUNNING_FILE ) ) {
                if( file_get_contents( RUNNING_FILE ) === "DONE" ) {
                    $running = false;
                    $this->createLocal( "file", RUNNING_FILE, "put", "STARTED" );
                } else {
                    $running = true;
                }
            } else {
                $running = false;
                $this->createLocal( "file", RUNNING_FILE, "put", "STARTED" );
            }
        }
        if ( $running === false ) {
            $contents = $this->recurse();
            if ( CACHE_ENABLED === true ) {
                foreach ( $contents as $content ){
                    $dbx_cache_id = md5( "dbxContent" . $content[0] );
                    list( $object, , $mtime ) = $this->cache->fetch( $dbx_cache_id );
                    if ( $object === null || $object !== null && $mtime < $content[2] ) {
                        $metadata = $this->uploadFile( $content );
                        $content[2] = $metadata['modified'];
                        $content[4] = $metadata['rev'];
                        $this->cache->save( $dbx_cache_id, $content );
                    }
                }
                $this->cache->save( $dbx_cache_id_running, "DONE" );
            } else {
                foreach( $contents as $content ){
                    $this->uploadFile( $content );
                }
                $this->createLocal( "file", RUNNING_FILE, "put", "DONE" );
            }
        }
    }

    private function recurse ()
    {
        $localSyncPaths = array();
        $contents = array();
        $objects = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( DBX_SYNC_LOCAL ) );
        if ( CACHE_ENABLED === true ){
            $dbx_cache_id = md5( "dbxLocalContent" );
            list( $oldLocalSyncPaths ) = $this->cache->fetch( $dbx_cache_id );
        } else {
            if ( file_exists( SYNCPATHS_FILE ) ) {
                $oldLocalSyncPaths = json_decode ( file_get_contents( SYNCPATHS_FILE ) );
            }
        }
        foreach ( $objects as $object ) {
            clearstatcache( true, $object );
            if ( $object->isDir() ) {
                $dir = true;
                if ( count( glob( "$object/*", GLOB_NOSORT ) ) === 0 ) {
                    $object = rtrim( $object, "/." );
                } else {
                    continue;
                }
            } else {
                $dir = false;
            }
            $mtime = stat( $object )['mtime'];
            $object = str_replace( DBX_SYNC_LOCAL, '', $object );
            $localSyncPaths[] = $object;
            if ( $oldLocalSyncPaths !== null && isset( $oldLocalSyncPaths[0] ) && $oldLocalSyncPaths[0] !== '' ) {
                $needle = $object;
                $found = false;
                while ( $found === false ) {
                    $found = array_search( $needle, $oldLocalSyncPaths );
                    if ( $found !== false ) {
                        unset( $oldLocalSyncPaths[ $found ] );
                    }
                    if ( $needle !== dirname( $object ) ) {
                        $needle = dirname( $object );
                    } else {
                        break;
                    }
                }
            }
            if ( !empty( $object ) ) {
                $contents[] = array( strtolower($object), $object, $mtime, $dir, null );
            }
        }
        if ( $oldLocalSyncPaths !== null && isset( $oldLocalSyncPaths[0] ) && $oldLocalSyncPaths[0] !== '' ) {
            foreach ( $oldLocalSyncPaths as $oldSyncPath ) {
                $this->deleteRemoteFile( $oldSyncPath );
            }
        }
        if ( CACHE_ENABLED === true ){
            $this->cache->save( $dbx_cache_id, array( $localSyncPaths ) );
        } else {
            if ( !file_exists( SYNCPATHS_FILE ) ) {
                $this->createLocal( "file", SYNCPATHS_FILE, "put", json_encode( $localSyncPaths ) );
            }
        }
        return $contents;
    }

    private static function sortCaseSensitvePath ( $a, $b )
    {
        $a = $a[0];
        $b = $b[0];
        $aDepth = substr_count( '/', $a );
        $bDepth = substr_count( '/', $b );
        if ( $aDepth === $bDepth ) {
            return strcmp( $bDepth, $aDepth );
        } else {
            return ( $aDepth < $bDepth ) ? -1 : 1;
        }
    }

    private function getCaseSensitivePath ( $caseInsensitivePath )
    {
        $caseSensitivePath = "";
        while ( $caseInsensitivePath !== false ) {
            list( $caseSensitivePath, $caseInsensitivePath ) = $this->getCaseSensitiveMetadata( $caseInsensitivePath, $caseSensitivePath );
            if ( $caseInsensitivePath === false ) {
                return $caseSensitivePath;
            }
            $caseInsensitiveFileExists = $this->fileExistsCI( $caseInsensitivePath );
            if ( $caseInsensitiveFileExists !== false ) {
                return $caseInsensitiveFileExists . $caseSensitivePath;
            }
            $caseInsensitivePath = $this->dbxClient->getMetadata( $caseInsensitivePath )['path'];
        }
    }

    private function getCaseSensitiveMetadata ( $caseInsensitivePath, $caseSensitivePath = "" )
    {
        $pathParts = explode( '/', $caseInsensitivePath );
        $pathLevels = count( $pathParts );
        $caseSensitivePath = "/" . array_pop( $pathParts ) . $caseSensitivePath;
        $pathLevels--;
        if ( $pathLevels === 1 ) {
            return array( $caseSensitivePath, false );
        } elseif ( $pathLevels >= 2 ) {
            $caseSensitivePath = "/" . array_pop( $pathParts ) . $caseSensitivePath;
            $pathLevels--;
            if ( $pathLevels === 1 ) {
                return array( $caseSensitivePath, false );
            }
        }
        $caseInsensitivePath = implode( '/', $pathParts );
        return array( $caseSensitivePath, $caseInsensitivePath );
    }

    private function fileExistsSingle ( $file )
    {
        if ( file_exists( $file ) === true ) {
            return $file;
        }
        $lowerfile = strtolower( $file );
        foreach ( glob ( dirname( $file ) . '/*') as $file ) {
            if ( strtolower( $file ) === $lowerfile ) {
                return $file;
            }
        }
        return false;
    }

    private function fileExistsCI ( $filePath )
    {
        $localFilePath = DBX_SYNC_LOCAL . $filePath;

        if ( file_exists( $localFilePath ) === true ) {
            return $filePath;
        }
        // Split directory up into parts.
        $dirs = explode( '/', $localFilePath );
        $len = count( $dirs );
        $dir = '/';
        foreach ( $dirs as $i => $part ) {
            $dirpath = $this->fileExistsSingle( $dir . $part );
            if ( $dirpath === false ) {
                return false;
            }
            $dir = $dirpath;
            $dir .= ( ( $i > 0 ) && ( $i < $len - 1 ) ) ? '/' : '';
        }
        $dir = str_replace( DBX_SYNC_LOCAL, '', $dir );
        return $dir;
    }

    private function getFile ( $content )
    {
        $localSyncPath = DBX_SYNC_LOCAL . $content[1];
        if ( $content[3] === false ){
            $remoteSyncPath = DBX_SYNC_REMOTE . $content[1];
            $tempLocalPath = DBX_TMP_DIR . basename( $content[1] );
            $this->createLocal( "dir", dirname( $localSyncPath ) );
            $fp = $this->createLocal( "file", $tempLocalPath, "open" );
            $this->dbxClient->getFile( $remoteSyncPath, $fp );
            $this->createLocal( "file", $tempLocalPath, "close", $fp );
            for( $i = 0; $i < 60 * 3; $i++ ) {
                if ( file_exists( $tempLocalPath ) === true ) {
                    break;
                }
                sleep(1);
            }
            if ( file_exists( $tempLocalPath ) === false ){
                return false;
            }
            rename( $tempLocalPath, $localSyncPath );
        } else {
            $this->createLocal( "dir", $localSyncPath );
        }
        return stat( $localSyncPath )['mtime'];
    }

    private function uploadFile ( $content )
    {
        $remoteSyncPath = DBX_SYNC_REMOTE . $content[1];
        $localSyncPath = DBX_SYNC_LOCAL . $content[1];
        $pathError = dbx\Path::findError( $remoteSyncPath );
        $tempLocalPath = DBX_TMP_DIR . basename( $content[1] );
        if ( $pathError !== null ) {
            $this->grav['log']->error("Dropbox remote sync path error: $pathError");
        } else {
            if ( $content[3] === false ) {
                $size = null;
                if ( stream_is_local( $localSyncPath ) ) {
                    $size = filesize( $localSyncPath );
                }
                $writeMode = dbx\WriteMode::add();
                if ( $content[4] !== null ){
                    $writeMode = dbx\WriteMode::update( $rev );
                }
                $fp = fopen( $localSyncPath, "rb" );
                $metadata = $this->dbxClient->uploadFile( $remoteSyncPath, $writeMode, $fp, $size );
                fclose( $fp );
                $metadata['modified'] = strtotime( $metadata['modified'] );
                try {
                    $touched = touch( $localSyncPath, $metadata['modified'] );
                }
                catch ( \Exception $e ) {
                    $touched = false;
                }
                if( $touched === false ){
                    copy( $localSyncPath, $tempLocalPath );
                    unlink( $localSyncPath );
                    rename( $tempLocalPath, $localSyncPath );
                    touch( $localSyncPath, $metadata['modified'] );
                }
                clearstatcache( true, $localSyncPath );
                return $metadata;
            } else {
                $this->dbxClient->createFolder( $remoteSyncPath );
            }
        }
    }

    private function createLocal ( $type, $name, $action = null, $data = null )
    {
        switch ( $type ) {
            case "file":
                switch ( $action ) {
                    case "open":
                        $fp = fopen( $name, "w+b" );
                        if ( flock( $fp, LOCK_EX | LOCK_NB ) ) {
                            return $fp;
                        } else {
                            return $fp;
                        }
                        break;
                    case "close":
                        $fp = $data;
                        flock( $fp, LOCK_UN );
                        fclose( $fp );
                        $oldmask = umask(0);
                        chmod( $name, 0660 );
                        umask( $oldmask );
                        break;
                    case "put":
                        file_put_contents( $name, $data, LOCK_EX | LOCK_NB );
                        $oldmask = umask(0);
                        chmod( $name, 0660 );
                        umask( $oldmask );
                        break;
                }
                break;
            case "dir":
                if ( !file_exists( $name ) ) {
                    $oldmask = umask(0);
                    mkdir( $name, 0770, true );
                    umask( $oldmask );
                }
                break;
        }
    }

    private function deleteLocalFile ( $content )
    {
        if ( $content !== null ) {
            $object = DBX_SYNC_LOCAL . $content;
            if ( file_exists( $object ) ) {
                if ( is_dir( $object ) ){
                    $inner_objects =
                        new \RecursiveIteratorIterator(
                            new \RecursiveDirectoryIterator(
                                $object,
                                \RecursiveDirectoryIterator::SKIP_DOTS
                            ),
                            \RecursiveIteratorIterator::CHILD_FIRST
                        );
                    foreach ( $inner_objects as $inner_object ) {
                        if ( $inner_object->isDir() ) {
                            rmdir( $inner_object );
                        } else {
                            unlink( $inner_object );
                        }
                    }
                    rmdir( $object );
                } else {
                    unlink( $object );
                }
            }
        }
    }

    private function deleteRemoteFile ( $content )
    {
        if ( $content !== null ) {
            $object = DBX_SYNC_REMOTE . $content;
            if ( $object !== '/' && !empty( $object ) ) {
                $metadata = $this->dbxClient->getMetadata( $object );
                if ( $metadata !== null ) {
                    $this->dbxClient->delete( $object );
                }
            }
        }
    }

    // Create AppInfo to getClient
    private function getAppConfig ()
    {
        try {
            $appInfo = new dbx\AppInfo( DBX_APP_KEY, DBX_APP_SECRET );
        }
        catch (dbx\AppInfoLoadException $ex) {
            $this->grav['log']->error("Dropbox configuration unable to load: " . $ex->getMessage());
        }
        return $appInfo;
    }

    // Try to set dbxClient
    private function getClient ()
    {
        if ( empty( DBX_APP_TOKEN ) ) {
            $this->dbxClient = false;
            $this->grav['log']->error("Dropbox Oauth2 token isn't set: Please get one from https://dropbox.com/developers/apps.");
            return false;
        }
        $appInfo = $this->getAppConfig();
        $clientIdentifier = getenv('HTTP_HOST');
        $userLocale = null;
        $this->dbxClient = new dbx\Client( DBX_APP_TOKEN, $clientIdentifier, $userLocale, $appInfo->getHost() );
        if ( $this->dbxClient !== false ) {
            return true;
        } else {
            $this->grav['log']->error("Dropbox couldn't authorize client.");
            // TODO: send email alert (failed attempt to authorize);
            return false;
        }
    }
}

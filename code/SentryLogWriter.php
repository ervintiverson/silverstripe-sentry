<?php

namespace SilverStripeSentry;

require_once THIRDPARTY_PATH . '/Zend/Log/Writer/Abstract.php';

/**
 * The SentryLogWriter class simply acts as a bridge between the configured Sentry 
 * adaptor and SilverStripe's {@link SS_Log}.
 * 
 * Usage in your project's _config.php for example (See README for expanded examples).
 *  
 *    SS_Log::add_writer(SentryLogWriter::factory(), '<=');
 * 
 * @author  Russell Michell 2017 <russ@theruss.com>
 * @package silverstripe/sentry
 * @todo    Baking-in the "client" service dependency is sub-optimal. Injector should handle this.
 * @todo    Complete and test a YML configured "release" feature, with data taken from composer.lock
 * @todo    Incorporate Sentry's Breadcrumbs feature
 */

class SentryLogWriter extends \Zend_Log_Writer_Abstract
{
    
    /**
     * @const string
     */
    const SLW_NOOP = 'Unavailable';
    
    /**
     * For flexibility, the factory should be the usual entry point into this class,
     * but there's no reason the constructor can't be called directly if for example, only
     * local errror-reporting is required.
     * 
     * @param  array $config
     * @return SentryLogWriter
     */
    public static function factory($config = [])
    {
        $env = isset($config['env']) ? $config['env'] : null;
        $tags = isset($config['tags']) ? $config['tags'] : [];
        $extra = isset($config['extra']) ? $config['extra'] : [];

        $writer = \Injector::inst()->get('SentryLogWriter');

        // Set default environment
        if (is_null($env)) {
            $env = \Director::get_environment_type();
        }

        // Set all available user-data
        $userData = $writer->defaultUserData();
        if ($member = \Member::currentUser()) {
            $userData = $writer->defaultUserData($member);
        }

        // Set any available tags available in SS config
        $tags = array_merge($writer->defaultTags(), $tags);

        $writer->client->setData('env', $env);
        $writer->client->setData('user', $userData);
        $writer->client->setData('tags', $tags);
        $writer->client->setData('extra', $extra);

        return $writer;
    }
    
    /**
     * Used mostly by unit tests.
     * 
     * @return ClientAdaptor 
     */
    public function getClient()
    {
        return $this->client;
    }
    
    /**
     * Returns a default set of additional data specific to the user's part in
     * the request.
     * 
     * @param  Member $member
     * @return array
     */
    public function defaultUserData(\Member $member = null)
    {
        return [
            'IP-Address'    => $this->getIP(),
            'ID'            => $member ? $member->getField('ID') : self::SLW_NOOP,
            'Email'         => $member ? $member->getField('Email') : self::SLW_NOOP,
        ];
    }
    
    /**
     * Returns a default set of additional tags we wish to send to Sentry.
     * By default, Sentry reports on several mertrics, and we're already sending 
     * {@link Member} data. But there are additional data that would be useful
     * for debugging via the Sentry UI.
     * 
     * @return array
     */
    public function defaultTags()
    {
        return [
            'Request-Method'=> $this->getReqMethod(),
            'Request-Type'  => $this->getRequestType(),
            'SAPI'          => $this->getSAPI(),
            'SS-Version'    => $this->getPackageInfo('silverstripe/framework'),
            'Peak-Memory'   => $this->getPeakMemory()
        ];
    }
    
    /**
     * _write() forms the entry point into the physical sending of the error. The 
     * sending itself is done by the current client's `send()` method.
     * 
     * @param  array $event An array of data that is created in, and arrives here via {@link SS_Log::log()} and {@link Zend_Log::log}.
     *                      via {@link SS_Log::log()} and {@link Zend_Log::log}.
     * @return void
     */
    protected function _write($event)
    {
        $message = $event['message']['errstr'];                             // From SS_Log::log()
        // The complete compliment of these data come via the Raven_Client::xxx_context() methods
        $data = [
            'timestamp' => strtotime($event['timestamp']),                  // From Zend_Log::log()
            'extra'     => isset($event['extra']) ? $event['extra'] : ''    // From _config.php (Optional)
        ];
        $trace = \SS_Backtrace::filter_backtrace(debug_backtrace(), ['SentryLogWriter->_write']);
        
        $this->client->send($message, [], $data, $trace);
    }
    
    /**
     * Return the version of $pkg taken from composer.lock.
     * 
     * @param  string $pkg e.g. "silverstripe/framework"
     * @return string
     */
    public function getPackageInfo($pkg)
    {
        $lockFileJSON = BASE_PATH . '/composer.lock';

        if (!file_exists($lockFileJSON) || !is_readable($lockFileJSON)) {
            return self::SLW_NOOP;
        }

        $lockFileData = json_decode(file_get_contents($lockFileJSON), true);

        foreach ($lockFileData['packages'] as $package) {
            if ($package['name'] === $pkg) {
                return $package['version'];
            }
        }
        
        return self::SLW_NOOP;
    }
    
    /**
     * Return the IP address of the relevant request.
     * 
     * @return string
     */
    public function getIP()
    {
        $req = \Injector::inst()->create('SS_HTTPRequest', $this->getReqMethod(), '');
        if ($ip = $req->getIP()) {
            return $ip;
        }
        
        return self::SLW_NOOP;
    }
    
    /**
     * What sort of request is this? (A harder question to answer than you might
     * think: http://stackoverflow.com/questions/6275363/what-is-the-correct-terminology-for-a-non-ajax-request)
     * 
     * @return string
     */
    public function getRequestType()
    {
        $isCLI = $this->getSAPI() !== 'cli';
        $isAjax = \Director::is_ajax();

        return $isCLI && $isAjax ? 'AJAX' : 'Non-Ajax';
    }
    
    /**
     * Return peak memory usage.
     * 
     * @return float
     */
    public function getPeakMemory()
    {
        $peak = memory_get_peak_usage(true) / 1024 / 1024;
        
        return (string) round($peak, 2) . 'Mb';
    }
    
    /**
     * Basic User-Agent check and return.
     * 
     * @return string
     */
    public function getUserAgent()
    {
        if (!empty($ua = @$_SERVER['HTTP_USER_AGENT'])) {
            return $ua;
        }
        
        return self::SLW_NOOP;
    }
    
    /**
     * Basic reuqest method check and return.
     * 
     * @return string
     */
    public function getReqMethod()
    {
        if (!empty($method = @$_SERVER['REQUEST_METHOD'])) {
            return $method;
        }
        
        return self::SLW_NOOP;
    }
    
    /**
     * @return string
     */
    public function getSAPI()
    {
        return php_sapi_name();
    }
    
}
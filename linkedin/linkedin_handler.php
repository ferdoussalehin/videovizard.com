<?php
class LinkedInCredentialHandler {
    private $storageFile;
    private $userInfoFile;
    private $manageOptionsFile;
    private $baseUrl;
    private $logFile;

    public $clientId;
    public $clientSecret;
    public $code;
    public $accessToken;
    public $redirectedUrl;
    public $authCredentials;
    public $sfwp_linkedIn_user_info;
    public $tokenLife = 0;
    public $URN = "";

    function __construct($baseUrl = 'http://localhost:8000') {
        $this->baseUrl = $baseUrl;
        $this->storageFile = __DIR__ . '/linkedin_session.json';
        $this->userInfoFile = __DIR__ . '/linkedin_user_info.json';
        $this->manageOptionsFile = __DIR__ . '/linkedin_manage_options.json';
        $this->logFile = __DIR__ . '/linkedin_api.log';
        
        // Load environment variables from .env file if it exists
        $this->loadEnvFile();
        
        $this->loadSessionData();
        $this->checkTokenLife();
    }

    /**
     * Load environment variables from .env file
     */
    private function loadEnvFile() {
        $envFile = __DIR__ . '/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                // Skip comments
                if (strpos(trim($line), '#') === 0) {
                    continue;
                }
                
                // Parse VAR=VALUE format
                if (strpos($line, '=') !== false) {
                    list($name, $value) = explode('=', $line, 2);
                    $name = trim($name);
                    $value = trim($value);
                    
                    // Remove quotes if present
                    if (preg_match('/^([\'"])(.*)\1$/', $value, $matches)) {
                        $value = $matches[2];
                    }
                    
                    putenv("$name=$value");
                }
            }
        }
    }

    private function loadJsonFile($file) {
        try {
            if (file_exists($file)) {
                $content = file_get_contents($file);
                $data = json_decode($content, true);
                return is_array($data) ? $data : [];
            }
            return [];
        } catch (Exception $e) {
            $this->logToFile("Error reading JSON file: " . $e->getMessage(), 'ERROR');
            return [];
        }
    }

    private function saveJsonFile($file, $data) {
        try {
            // Ensure the directory exists
            $dir = dirname($file);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            // Write the data
            file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
        } catch (Exception $e) {
            $this->logToFile("Error updating JSON file: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }

    /**
     * Load session data (non-sensitive)
     */
    function loadSessionData() {
        try {
            // Load session data (non-sensitive)
            $this->authCredentials = $this->loadJsonFile($this->storageFile);
            $this->sfwp_linkedIn_user_info = $this->loadJsonFile($this->userInfoFile);
            
            // Get sensitive credentials ONLY from environment variables
            $this->clientId = getenv('LINKEDIN_CLIENT_ID') ?: '';
            $this->clientSecret = getenv('LINKEDIN_CLIENT_SECRET') ?: '';
            
            // Get non-sensitive data from file
            $this->redirectedUrl = isset($this->authCredentials['redirected_url']) ? 
                $this->authCredentials['redirected_url'] : $this->getDefaultRedirectedUrl();
                
            // Get access token from session data
            $this->accessToken = isset($this->authCredentials['access_token']) ? 
                $this->authCredentials['access_token'] : '';
        } catch (Exception $e) {
            $this->logToFile("Error loading session data: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }

    /**
     * Save redirect URL and state (non-sensitive data)
     */
    function saveRedirectUrl($redirectedUrl = "") {
        try {
            // Load existing session data
            $sessionData = $this->loadJsonFile($this->storageFile);
            
            // Update only non-sensitive data
            $sessionData['redirected_url'] = $redirectedUrl;
            $sessionData['state'] = time() . uniqid();
            
            // Save back to file
            $this->saveJsonFile($this->storageFile, $sessionData);
        } catch (Exception $e) {
            $this->logToFile("Error saving redirect URL: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }

    /**
     * Validate credentials are set
     * @return bool True if credentials are set, false otherwise
     */
    function hasValidCredentials() {
        return !empty($this->clientId) && !empty($this->clientSecret);
    }

    /**
     * Set a new random state value for OAuth security
     */
    function setState() {
        try {
            $sessionData = $this->loadJsonFile($this->storageFile);
            $state = time() . uniqid();
            $sessionData['state'] = $state;
            $this->saveJsonFile($this->storageFile, $sessionData);
        } catch (Exception $e) {
            $this->logToFile("Error setting state: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }

    /**
     * Get the current state value for OAuth verification
     */
    function getState() {
        try {
            $sessionData = $this->loadJsonFile($this->storageFile);
            return isset($sessionData['state']) ? $sessionData['state'] : "";
        } catch (Exception $e) {
            $this->logToFile("Error getting state: " . $e->getMessage(), 'ERROR');
            return "";
        }
    }

    function getCode() {
        try {
            $sessionData = $this->loadJsonFile($this->storageFile);
            return isset($sessionData['code']) ? $sessionData['code'] : "";
        } catch (Exception $e) {
            $this->logToFile("Error getting code: " . $e->getMessage(), 'ERROR');
            return "";
        }
    }

    function setCode($code) {
        try {
            $sessionData = $this->loadJsonFile($this->storageFile);
            $sessionData['code'] = $code;
            $this->saveJsonFile($this->storageFile, $sessionData);
            $this->getAccessTokenFromLinkedIn();
        } catch (Exception $e) {
            $this->logToFile("Error setting code: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }

    function getAccessToken() {
        try {
            if (!isset($this->authCredentials['access_token']) || empty($this->authCredentials['access_token'])) {
                $this->logToFile("Access token not found or empty", 'ERROR');
                return ''; // Return empty string instead of throwing exception
            }
            return $this->authCredentials['access_token'];
        } catch (Exception $e) {
            $this->logToFile("Error getting access token: " . $e->getMessage(), 'ERROR');
            return ''; // Return empty string in case of exception
        }
    }

    function getTokenExpiredInTime() {
        try {
            $sessionData = $this->loadJsonFile($this->storageFile);
            return isset($sessionData['expired_in']) ? $sessionData['expired_in'] : "";
        } catch (Exception $e) {
            $this->logToFile("Error getting token expiration time: " . $e->getMessage(), 'ERROR');
            return "";
        }
    }

    private function logToFile($message, $type = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp][$type] $message" . PHP_EOL;
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
    }

    private function makeRequest($url, $method = 'GET', $headers = [], $body = null) {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
            // Create a map of headers to prevent duplicates
            $headerMap = [];
            foreach ($headers as $header) {
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $headerName = trim($parts[0]);
                    $headerValue = trim($parts[1]);
                    $headerMap[strtolower($headerName)] = "$headerName: $headerValue";
                } else {
                    // If the header doesn't have a colon, keep it as is
                    $headerMap[] = $header;
                }
            }
            
            // Add default headers if they don't already exist
            $defaultHeaders = [
                'content-type' => 'Content-Type: application/json',
                'x-restli-protocol-version' => 'X-Restli-Protocol-Version: 2.0.0',
                'x-li-format' => 'x-li-format: json'
            ];
            
            foreach ($defaultHeaders as $key => $value) {
                if (!isset($headerMap[$key])) {
                    $headerMap[$key] = $value;
                }
            }
            
            // Convert header map back to array
            $finalHeaders = array_values($headerMap);
            
            curl_setopt($ch, CURLOPT_HTTPHEADER, $finalHeaders);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For testing only
            curl_setopt($ch, CURLOPT_VERBOSE, false); // Disable verbose output

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                if ($body) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                }
            }

            // No longer logging request information
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            // Log only the response information
            $this->logToFile("RESPONSE CODE: $httpCode");
            if ($response) {
                $this->logToFile("RESPONSE: " . $this->truncateBody($response));
            }
            
            if ($error) {
                $this->logToFile("CURL ERROR: $error", 'ERROR');
                throw new Exception("CURL Error: " . $error);
            }
            
            curl_close($ch);

            return [
                'body' => $response,
                'response_code' => $httpCode,
                'error' => $error
            ];
        } catch (Exception $e) {
            $this->logToFile("Error making request: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }
    
    /**
     * Sanitize headers to remove sensitive information like auth tokens
     */
    private function sanitizeHeaders($headers) {
        $sanitized = [];
        foreach ($headers as $header) {
            if (stripos($header, 'authorization:') === 0) {
                // Only show the first 20 chars of the token
                $parts = explode(' ', $header, 3);
                if (count($parts) >= 2) {
                    $token = $parts[1];
                    $sanitized[] = "Authorization: " . substr($token, 0, 20) . "...";
                } else {
                    $sanitized[] = "Authorization: [hidden]";
                }
            } else {
                $sanitized[] = $header;
            }
        }
        return json_encode($sanitized);
    }
    
    /**
     * Truncate long response/request bodies to a reasonable length
     */
    private function truncateBody($body) {
        $maxLength = 500; // Maximum characters to show
        $bodyStr = is_string($body) ? $body : json_encode($body);
        
        if (strlen($bodyStr) > $maxLength) {
            return substr($bodyStr, 0, $maxLength) . "... [truncated]";
        }
        
        return $bodyStr;
    }

    function getAccessTokenFromLinkedIn() {
        try {
            // Verify credentials are set
            if (!$this->hasValidCredentials()) {
                throw new Exception("LinkedIn API credentials not set. Please configure LINKEDIN_CLIENT_ID and LINKEDIN_CLIENT_SECRET environment variables.");
            }
            
            $params = [
                'grant_type' => 'authorization_code',
                'code' => $this->getCode(),
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $this->redirectedUrl
            ];

            $requested_url = "https://www.linkedin.com/oauth/v2/accessToken?" . http_build_query($params);
            
            $response = $this->makeRequest($requested_url);
            $json_response = json_decode($response['body'], true);
            
            if (isset($json_response['access_token'])) {
                $this->setAccessTokenAndExpireInTime($json_response['access_token'], $json_response['expires_in']);
                $params = ['oauth2_access_token' => $json_response['access_token']];
                $this->api_request($params, "me");
            } else {
                $this->logToFile("LinkedIn OAuth Error: " . print_r($json_response, true), 'ERROR');
                throw new Exception("Failed to get access token from LinkedIn");
            }
        } catch (Exception $e) {
            $this->logToFile("LinkedIn OAuth Exception: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }

    function setAccessTokenAndExpireInTime($accessToken, $expireInTime) {
        $sessionData = $this->loadJsonFile($this->storageFile);
        $sessionData['access_token'] = $accessToken;
        $sessionData['expired_in'] = $expireInTime;
        $sessionData['expired_at'] = time() + $expireInTime;
        $this->saveJsonFile($this->storageFile, $sessionData);
    }

    function getLinkedInLoginLink() {
        // Verify credentials are set
        if (!$this->hasValidCredentials()) {
            $this->logToFile("Cannot generate login link: LinkedIn API credentials not set", 'ERROR');
            return '#';
        }
        
        // Simplified scopes based on LinkedIn API requirements
        $scopes = [
            'openid',
            'profile',
            'email',
            'w_member_social' // Required for sharing posts
        ];
        
        $params = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectedUrl,
            'state' => $this->getState(),
            'scope' => implode(' ', $scopes)
        ];
        
        return "https://www.linkedin.com/oauth/v2/authorization?" . http_build_query($params);
    }

    function getDefaultRedirectedUrl() {
        return $this->baseUrl . "/callback.php";
    }

    public function checkTokenLife() {
        if (!isset($this->authCredentials['expired_at'])) {
            $this->tokenLife = 0;
            return;
        }
        $life = intval($this->authCredentials['expired_at']) - time();
        $this->tokenLife = max(0, $life);
    }

    public function api_request($params = [], $service = "me") {
        try {
            $requestedUrl = "https://api.linkedin.com/v2/" . $service . "?" . http_build_query($params);
            
            $result = $this->makeRequest($requestedUrl);
            $json_response = json_decode($result['body'], true);
            
            if (isset($json_response['id']) && $service == 'me') {
                // Store the user ID as URN
                $this->set_user_info(['id' => $json_response['id']]);
                // Also store the full profile info
                $this->saveJsonFile($this->userInfoFile, [
                    'userURN' => $json_response['id'],
                    'profile' => $json_response
                ]);
            }
            return $json_response;
        } catch (Exception $e) {
            $this->logToFile("API request failed: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }

    public function post_api_request($params, $header, $service, $requestedUrl) {
        try {
            // Make sure we don't have duplicate Content-Type headers
            $hasContentType = false;
            foreach ($header as $h) {
                if (stripos($h, 'Content-Type:') === 0) {
                    $hasContentType = true;
                    break;
                }
            }
            
            if (!$hasContentType) {
                $header[] = 'Content-Type: application/json';
            }
            
            $result = $this->makeRequest($requestedUrl, 'POST', $header, $params);
            return $result;
        } catch (Exception $e) {
            $this->logToFile("Post API request failed: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }

    public function set_user_info($user) {
        $params = ['userURN' => $user['id']];
        $this->saveJsonFile($this->userInfoFile, $params);
    }

    public function get_user_URN() {
        try {
            // Initialize user info file if it doesn't exist
            if (!file_exists($this->userInfoFile)) {
                $this->logToFile("Creating user info file");
                file_put_contents($this->userInfoFile, json_encode([]));
            }
        
            $userInfo = $this->loadJsonFile($this->userInfoFile);
        
            // If URN is not cached, fetch it
            if (empty($userInfo) || !isset($userInfo['userURN'])) {
                $this->logToFile("Fetching user URN from LinkedIn");
        
                // Check if access token exists
                if (!isset($this->authCredentials['access_token']) || empty($this->authCredentials['access_token'])) {
                    $this->logToFile("No access token available", 'ERROR');
                    return '';
                }
                
                try {
                    $headers = [
                        'Authorization: Bearer ' . $this->authCredentials['access_token']
                    ];
            
                    // Get profile using OpenID userinfo endpoint
                    $userInfoUrl = "https://api.linkedin.com/v2/userinfo";
                    $result = $this->makeRequest($userInfoUrl, 'GET', $headers);
            
                    if ($result['response_code'] == 200) {
                        $profile = json_decode($result['body'], true);
                        if (isset($profile['sub'])) {
                            // In OpenID Connect, 'sub' is the unique identifier
                            $userURN = 'urn:li:person:' . $profile['sub'];
                            $userData = [
                                'userURN' => $userURN,
                                'profile' => $profile
                            ];
                            $this->saveJsonFile($this->userInfoFile, $userData);
                            $this->logToFile("User URN saved: " . $userURN);
                            return $userURN;
                        }
                    }
                } catch (Exception $inner_e) {
                    $this->logToFile("Error fetching URN: " . $inner_e->getMessage(), 'ERROR');
                }
        
                $this->logToFile("Failed to fetch user URN", 'ERROR');
                return '';
            }
        
            return $userInfo['userURN'];
        } catch (Exception $e) {
            $this->logToFile("Error: " . $e->getMessage(), 'ERROR');
            return '';
        }
    }
    

    public function send_to_linkedIn_feeds($contents, $shareMode) {
        try {
            // First, make sure we have the user's URN
            $userURN = $this->get_user_URN();
            if (empty($userURN)) {
                $this->logToFile("No user URN available. Cannot post to LinkedIn.", 'ERROR');
                return false;
            }

            $headers = [
                'Authorization: Bearer ' . $this->getAccessToken()
            ];

            $requestedUrl = "https://api.linkedin.com/v2/ugcPosts";

            // Prepare the base share content
            $shareContent = [
                "com.linkedin.ugc.ShareContent" => [
                    "shareCommentary" => [
                        "text" => $contents['title']
                    ],
                    "shareMediaCategory" => "NONE"
                ]
            ];

            // Add media if URL is provided
            if (!empty($contents['url'])) {
                $shareContent["com.linkedin.ugc.ShareContent"]["shareMediaCategory"] = "ARTICLE";
                $shareContent["com.linkedin.ugc.ShareContent"]["media"] = [[
                    "status" => "READY",
                    "description" => [
                        "text" => $contents['description'] ?? ''
                    ],
                    "originalUrl" => $contents['url'],
                    "title" => [
                        "text" => $contents['title']
                    ]
                ]];
            }

            // Prepare the complete request payload
            $params = [
                'author' => $userURN,
                'lifecycleState' => 'PUBLISHED',
                'specificContent' => $shareContent,
                'visibility' => [
                    'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'
                ]
            ];

            $this->logToFile("Posting to LinkedIn: " . substr($contents['title'], 0, 50));
            
            try {
                $result = $this->post_api_request(json_encode($params), $headers, $shareMode, $requestedUrl);
                
                if ($result['response_code'] == 201) {
                    $this->logToFile("Post successful");
                    return true;
                } else {
                    $this->logToFile("Post failed: " . $result['response_code'], 'ERROR');
                    return false;
                }
            } catch (Exception $api_e) {
                $this->logToFile("API error: " . $api_e->getMessage(), 'ERROR');
                return false;
            }
        } catch (Exception $e) {
            $this->logToFile("Exception: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }

    public function uploadImage($contents, $requestedurl) {
        try {
            $headers = [
                'Content-Type: application/binary',
                'X-Restli-Protocol-Version: 2.0.0',
                'x-li-format: json',
                'Authorization: Bearer ' . $this->getAccessToken()
            ];

            $result = $this->makeRequest($requestedurl, 'POST', $headers, $contents['thumbnails']);
            if ($result['response_code'] != 201) {
                throw new Exception("Failed to upload image: " . print_r($result, true));
            }
            return true;
        } catch (Exception $e) {
            $this->logToFile("Failed to upload image: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }

    public function registerImageUrl() {
        try {
            // Get the user URN and extract just the ID part
            $userURN = $this->get_user_URN();
            $userId = '';
            
            if (preg_match('/urn:li:person:(.+)/', $userURN, $matches)) {
                $userId = $matches[1];
            } else {
                throw new Exception("Invalid user URN format: " . $userURN);
            }
            
            $requestedurl = "https://api.linkedin.com/v2/assets?action=registerUpload";
            $params = [
                'registerUploadRequest' => [
                    'recipes' => ["urn:li:digitalmediaRecipe:feedshare-image"],
                    "owner" => "urn:li:person:" . $userId,
                    "serviceRelationships" => [[
                        "relationshipType" => "OWNER",
                        "identifier" => "urn:li:userGeneratedContent"
                    ]]
                ]
            ];

            $headers = [
                'Content-Type: application/json',
                'X-Restli-Protocol-Version: 2.0.0',
                'x-li-format: json',
                'Authorization: Bearer ' . $this->getAccessToken()
            ];

            $result = $this->makeRequest($requestedurl, 'POST', $headers, json_encode($params));
            
            if ($result['response_code'] == 200) {
                $body = json_decode($result['body'], true);
                $uploadUrl = $body['value']['uploadMechanism']['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest']['uploadUrl'];
                $asset = $body['value']['asset'];
                return [
                    'uploadedUrl' => $uploadUrl,
                    'assets' => $asset
                ];
            }
            throw new Exception("Failed to register image URL: " . print_r($result, true));
        } catch (Exception $e) {
            $this->logToFile("Failed to register image URL: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }

    public function get_linkedIn_manage_option_settings() {
        return $this->loadJsonFile($this->manageOptionsFile);
    }

    public function set_linkedIn_manage_option_settings($option) {
        try {
            $manageOptionSetting = $this->get_linkedIn_manage_option_settings();
            $manageOptionSetting['linkedIn_button_showing_status'] = $option['linkedIn_button_showing_status'];
            $manageOptionSetting['linkedIn_shared_type'] = $option['linkedIn_shared_type'];
            $this->saveJsonFile($this->manageOptionsFile, $manageOptionSetting);
            return true;
        } catch (Exception $e) {
            $this->logToFile("Failed to set LinkedIn manage options: " . $e->getMessage(), 'ERROR');
            throw $e;
        }
    }

    public function send_to_linkedIn_feeds_with_image($contents, $shareMode) {
        try {
            // First, make sure we have the user's URN
            $userURN = $this->get_user_URN();
            if (empty($userURN)) {
                $this->logToFile("No user URN available. Cannot post to LinkedIn.", 'ERROR');
                return false;
            }

            $headers = [
                'Authorization: Bearer ' . $this->getAccessToken()
            ];

            $requestedUrl = "https://api.linkedin.com/v2/ugcPosts";

            // Prepare the share content with image
            $shareContent = [
                "com.linkedin.ugc.ShareContent" => [
                    "shareCommentary" => [
                        "text" => $contents['title']
                    ],
                    "shareMediaCategory" => "IMAGE",
                    "media" => [[
                        "status" => "READY",
                        "description" => [
                            "text" => $contents['description'] ?? ''
                        ],
                        "media" => $contents['asset'],
                        "title" => [
                            "text" => $contents['title']
                        ]
                    ]]
                ]
            ];

            // Prepare the complete request payload
            $params = [
                'author' => $userURN,
                'lifecycleState' => 'PUBLISHED',
                'specificContent' => $shareContent,
                'visibility' => [
                    'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'
                ]
            ];

            $this->logToFile("Posting to LinkedIn with image: " . substr($contents['title'], 0, 50));
            
            try {
                $result = $this->post_api_request(json_encode($params), $headers, $shareMode, $requestedUrl);
                
                if ($result['response_code'] == 201) {
                    $this->logToFile("Post with image successful");
                    return true;
                } else {
                    $this->logToFile("Post with image failed: " . $result['response_code'], 'ERROR');
                    return false;
                }
            } catch (Exception $api_e) {
                $this->logToFile("API error: " . $api_e->getMessage(), 'ERROR');
                return false;
            }
        } catch (Exception $e) {
            $this->logToFile("Exception: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
} 
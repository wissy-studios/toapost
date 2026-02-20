

<?php

goto a1a1a1;

a0:
$GLOBALS['a'] = array(
    'config' => array(
        'token_len' => 32,
        'max_post' => 2000,
        'max_bio' => 500,
        'reward_post' => 10,
        'initial_toast' => 100,
        'initial_crystal' => 10,
        'clan_cost' => 50,
        'max_clan' => 50,
        'music_cost' => 10000,
        'toa_price' => 1000,
        'toa_daily' => 100,
        'toa_days' => 30,
        'post_reset' => 90
    ),
    'data_path' => 'data/',
    'files' => array(
        'users' => 'users.json',
        'posts' => 'posts.json',
        'threads' => 'threads.json',
        'clans' => 'clans.json',
        'trades' => 'trades.json',
        'wars' => 'wars.json',
        'territories' => 'territories.json',
        'playlist' => 'global_playlist.json',
        'banned' => 'banned.json',
        'announcement' => 'announcement.json'
    )
);

function a() {
    return $GLOBALS['a'];
}

function b($dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
}

function c($f, $d = array()) {
    $dir = dirname($f);
    b($dir);
    if (!file_exists($f)) {
        file_put_contents($f, json_encode($d));
        return $d;
    }
    $c = file_get_contents($f);
    if (empty($c)) {
        file_put_contents($f, json_encode($d));
        return $d;
    }
    $j = json_decode($c, true);
    if ($j === null) {
        file_put_contents($f, json_encode($d));
        return $d;
    }
    return is_array($j) ? $j : $d;
}

function d($f, $data) {
    $dir = dirname($f);
    b($dir);
    return file_put_contents($f, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

a1a1a1:

class Security {
    public static function e($data) {
        if (is_array($data)) {
            return array_map([self::class, 'e'], $data);
        }
        return htmlspecialchars($data ?? '', ENT_QUOTES, 'UTF-8');
    }
    
    public static function validateCsrf() {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
            http_response_code(403);
            die('CSRF validation failed');
        }
    }
    
    public static function sanitize($content) {
        if (empty($content)) return '';
        $content = strip_tags($content);
        $content = preg_replace('/javascript:/i', '', $content);
        $content = preg_replace('/data:/i', '', $content);
        $content = preg_replace('/on\w+\s*=/i', '', $content);
        return trim(self::e($content));
    }
    
    public static function validateUrl($url) {
        if (empty($url)) return '';
        $url = filter_var($url, FILTER_SANITIZE_URL);
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'https://' . $url;
        }
        return self::e($url);
    }
}

class UserManager {
    public static function getGlobalPlaylist() {
        return c(a()['data_path'] . a()['files']['playlist'], []);
    }
    
    public static function saveGlobalPlaylist($playlist) {
        return d(a()['data_path'] . a()['files']['playlist'], $playlist);
    }
    
    public static function addToGlobalPlaylist($username, $url, $title) {
        $playlist = self::getGlobalPlaylist();
        foreach ($playlist as $track) {
            if ($track['url'] === $url && $track['title'] === $title) {
                return false;
            }
        }
        $playlist[] = [
            'url' => $url,
            'title' => $title,
            'added_by' => $username,
            'added_at' => time(),
            'plays' => 0,
            'likes' => []
        ];
        return self::saveGlobalPlaylist($playlist);
    }
    
    public static function removeFromGlobalPlaylist($index) {
        $playlist = self::getGlobalPlaylist();
        if (isset($playlist[$index])) {
            unset($playlist[$index]);
            $playlist = array_values($playlist);
            return self::saveGlobalPlaylist($playlist);
        }
        return false;
    }
    
    public static function likeTrack($username, $index) {
        $playlist = self::getGlobalPlaylist();
        if (isset($playlist[$index])) {
            if (!in_array($username, $playlist[$index]['likes'])) {
                $playlist[$index]['likes'][] = $username;
                return self::saveGlobalPlaylist($playlist);
            }
        }
        return false;
    }
    
    public static function unlikeTrack($username, $index) {
        $playlist = self::getGlobalPlaylist();
        if (isset($playlist[$index])) {
            $key = array_search($username, $playlist[$index]['likes']);
            if ($key !== false) {
                unset($playlist[$index]['likes'][$key]);
                $playlist[$index]['likes'] = array_values($playlist[$index]['likes']);
                return self::saveGlobalPlaylist($playlist);
            }
        }
        return false;
    }
    
    public static function incrementTrackPlays($index) {
        $playlist = self::getGlobalPlaylist();
        if (isset($playlist[$index])) {
            $playlist[$index]['plays'] = ($playlist[$index]['plays'] ?? 0) + 1;
            return self::saveGlobalPlaylist($playlist);
        }
        return false;
    }

    public static function activateToaPlus($username) {
        $user = self::getUser($username);
        if (!$user) return false;
        
        $cfg = a()['config'];
        if ($user['crystals'] < $cfg['toa_price']) {
            return false;
        }
        
        $user['crystals'] -= $cfg['toa_price'];
        $user['toa_plus'] = [
            'active' => true,
            'activated' => time(),
            'expires' => time() + ($cfg['toa_days'] * 24 * 3600),
            'last_reward' => 0,
            'days_claimed' => 0
        ];
        
        return self::updateUser($username, $user);
    }
    
    public static function claimDailyReward($username) {
        $user = self::getUser($username);
        if (!$user || !isset($user['toa_plus']) || !$user['toa_plus']['active']) {
            return false;
        }
        
        if (time() > $user['toa_plus']['expires']) {
            $user['toa_plus']['active'] = false;
            self::updateUser($username, $user);
            return false;
        }
        
        $today = strtotime('today');
        $last_reward = $user['toa_plus']['last_reward'] ?? 0;
        
        if (date('Y-m-d', $last_reward) === date('Y-m-d')) {
            return false;
        }
        
        $cfg = a()['config'];
        $user['toasters'] += $cfg['toa_daily'];
        $user['stats']['toasters_earned'] += $cfg['toa_daily'];
        $user['toa_plus']['last_reward'] = time();
        $user['toa_plus']['days_claimed'] = ($user['toa_plus']['days_claimed'] ?? 0) + 1;
        
        return self::updateUser($username, $user);
    }
    
    public static function getToaPlusInfo($username) {
        $user = self::getUser($username);
        if (!$user || !isset($user['toa_plus'])) {
            return null;
        }
        return $user['toa_plus'];
    }

    public static function getUsers() {
        return c(a()['data_path'] . a()['files']['users'], []);
    }
    
    public static function saveUsers($users) {
        return d(a()['data_path'] . a()['files']['users'], $users);
    }
    
    public static function getUser($username) {
        $users = self::getUsers();
        if (isset($users[$username])) {
            $user_data = $users[$username];
            
            $defaults = [
                'stats' => [
                    'posts' => 0,
                    'threads' => 0,
                    'likes_given' => 0,
                    'likes_received' => 0,
                    'toasters_earned' => 0,
                    'consecutive_days' => 0,
                    'last_login' => 0,
                    'purchases' => 0,
                    'friends_count' => 0,
                    'clan_contributions' => 0
                ],
                'toa_plus' => [
                    'active' => false,
                    'activated' => 0,
                    'expires' => 0,
                    'last_reward' => 0,
                    'days_claimed' => 0
                ],
                'inventory' => [
                    'name_colors' => ['default'],
                    'badges' => ['none'],
                    'titles' => ['newbie'],
                    'profileicons' => ['default']
                ],
                'active_cosmetics' => [
                    'name_color' => 'default',
                    'badge' => 'none',
                    'title' => 'newbie',
                    'profileicon' => 'default'
                ],
                'friends' => [],
                'friend_requests' => [],
                'achievements' => [],
                'music_playlist' => [],
                'clan_id' => null,
                'clan_role' => null,
                'bio' => '',
                'location' => '',
                'website' => '',
                'theme' => 'light'
            ];
            
            $user_data = array_replace_recursive($defaults, $user_data);
            return array_merge(['username' => $username], $user_data);
        }
        return null;
    }
    
    public static function updateUser($username, $data) {
        $users = self::getUsers();
        if (!isset($users[$username])) return false;
        
        unset($data['username']);
        
        foreach ($data as $key => $value) {
            $users[$username][$key] = $value;
        }
        
        return self::saveUsers($users);
    }
    
    public static function equipCosmetic($username, $category, $item_id) {
        $users = self::getUsers();
        if (!isset($users[$username])) return false;
        
        $inventory = $users[$username]['inventory'][$category] ?? [];
        if (!in_array($item_id, $inventory)) {
            return false;
        }
        
        if (!isset($users[$username]['active_cosmetics'])) {
            $users[$username]['active_cosmetics'] = [
                'name_color' => 'default',
                'badge' => 'none',
                'title' => 'newbie'
            ];
        }
        
        $key_map = [
            'name_colors' => 'name_color',
            'badges' => 'badge',
            'titles' => 'title',
            'profileicons' => 'profileicon'
        ];
        
        if (!isset($key_map[$category])) return false;
        $target_key = $key_map[$category];
        
        $users[$username]['active_cosmetics'][$target_key] = $item_id;
        
        return self::saveUsers($users);
    }

    public static function isAutoAcceptEnabled($clan_id) {
        $clan = self::getClan($clan_id);
        return $clan && ($clan['settings']['auto_accept'] ?? false) === true;
    }
    
    public static function createUser($username, $password) {
        $users = self::getUsers();
        
        if (isset($users[$username])) {
            return false;
        }
        
        $cfg = a()['config'];
        
        $users[$username] = [
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'toasters' => $cfg['initial_toast'],
            'crystals' => $cfg['initial_crystal'],
            'is_admin' => false,
            'is_verified' => false,
            'theme' => 'light',
            'bio' => '',
            'location' => '',
            'website' => '',
            'joined' => time(),
            'last_active' => time(),
            'stats' => [
                'posts' => 0,
                'threads' => 0,
                'likes_given' => 0,
                'likes_received' => 0,
                'toasters_earned' => 0,
                'consecutive_days' => 0,
                'last_login' => 0,
                'purchases' => 0,
                'friends_count' => 0,
                'clan_contributions' => 0
            ],
            'inventory' => [
                'name_colors' => ['default'],
                'badges' => ['none'],
                'titles' => ['newbie'],
                'profileicons' => ['default']
            ],
            'active_cosmetics' => [
                'name_color' => 'default',
                'badge' => 'none',
                'title' => 'newbie',
                'profileicon' => 'default'
            ],
            'friends' => [],
            'friend_requests' => [],
            'achievements' => [],
            'music_playlist' => [],
            'clan_id' => null,
            'clan_role' => null
        ];
        
        return self::saveUsers($users);
    }
    
    public static function getLeaderboard($limit = 10) {
        $users = self::getUsers();
        $leaderboard = [];
        
        foreach ($users as $username => $data) {
            if (strtolower($username) === 'admin') continue;
            $score = ($data['stats']['posts'] * 10) + 
                    ($data['stats']['likes_received'] * 5) + 
                    ($data['stats']['consecutive_days'] * 20) +
                    ($data['toasters'] / 10) +
                    ($data['stats']['clan_contributions'] * 5);
            $leaderboard[$username] = $score;
        }
        
        arsort($leaderboard);
        return array_slice($leaderboard, 0, $limit, true);
    }
    
    public static function updateLoginStreak($username) {
        $user = self::getUser($username);
        if (!$user) return false;
        
        $today = strtotime('today');
        $last_login = $user['stats']['last_login'] ?? 0;
        $last_login_day = strtotime('today', $last_login);
        
        if ($last_login_day < strtotime('yesterday')) {
            $user['stats']['consecutive_days'] = 1;
        } elseif ($last_login_day < $today) {
            $user['stats']['consecutive_days']++;
        }
        
        $user['stats']['last_login'] = time();
        $user['last_active'] = time();
        
        return self::updateUser($username, $user);
    }
    
    public static function addFriend($username, $friend_username) {
        $users = self::getUsers();
        if (!isset($users[$username]) || !isset($users[$friend_username])) {
            return false;
        }
        
        if (!in_array($friend_username, $users[$username]['friends'])) {
            $users[$username]['friends'][] = $friend_username;
            $users[$username]['stats']['friends_count'] = count($users[$username]['friends']);
        }
        
        if (!in_array($username, $users[$friend_username]['friends'])) {
            $users[$friend_username]['friends'][] = $username;
            $users[$friend_username]['stats']['friends_count'] = count($users[$friend_username]['friends']);
        }
        
        return self::saveUsers($users);
    }
    
    public static function removeFriend($username, $friend_username) {
        $users = self::getUsers();
        if (!isset($users[$username])) return false;
        
        if (isset($users[$username]['friends'])) {
            $key = array_search($friend_username, $users[$username]['friends']);
            if ($key !== false) {
                unset($users[$username]['friends'][$key]);
                $users[$username]['friends'] = array_values($users[$username]['friends']);
                $users[$username]['stats']['friends_count'] = count($users[$username]['friends']);
            }
        }
        
        if (isset($users[$friend_username]['friends'])) {
            $key = array_search($username, $users[$friend_username]['friends']);
            if ($key !== false) {
                unset($users[$friend_username]['friends'][$key]);
                $users[$friend_username]['friends'] = array_values($users[$friend_username]['friends']);
                $users[$friend_username]['stats']['friends_count'] = count($users[$friend_username]['friends']);
            }
        }
        
        return self::saveUsers($users);
    }
    
    public static function sendFriendRequest($from, $to) {
        $users = self::getUsers();
        if (!isset($users[$from]) || !isset($users[$to])) {
            return false;
        }
        
        if (!isset($users[$to]['friend_requests'])) {
            $users[$to]['friend_requests'] = [];
        }
        
        if (!in_array($from, $users[$to]['friend_requests'])) {
            $users[$to]['friend_requests'][] = $from;
            return self::saveUsers($users);
        }
        
        return true;
    }
    
    public static function removeFriendRequest($to, $from) {
        $users = self::getUsers();
        if (!isset($users[$to])) return false;
        
        if (isset($users[$to]['friend_requests'])) {
            $key = array_search($from, $users[$to]['friend_requests']);
            if ($key !== false) {
                unset($users[$to]['friend_requests'][$key]);
                $users[$to]['friend_requests'] = array_values($users[$to]['friend_requests']);
                return self::saveUsers($users);
            }
        }
        
        return false;
    }
    
    public static function getClan($clan_id) {
        $clans = self::getClans();
        foreach ($clans as $clan) {
            if ($clan['id'] === $clan_id) {
                return $clan;
            }
        }
        return null;
    }
    
    public static function getClans() {
        return c(a()['data_path'] . a()['files']['clans'], []);
    }
    
    public static function saveClans($clans) {
        return d(a()['data_path'] . a()['files']['clans'], $clans);
    }
}

class PostManager {
    public static function getPosts() {
        return c(a()['data_path'] . a()['files']['posts'], []);
    }
    
    public static function savePosts($posts) {
        return d(a()['data_path'] . a()['files']['posts'], $posts);
    }
    
    public static function getThreads() {
        return c(a()['data_path'] . a()['files']['threads'], []);
    }
    
    public static function saveThreads($threads) {
        return d(a()['data_path'] . a()['files']['threads'], $threads);
    }
    
    public static function createPost($author, $content, $thread_id = null) {
        $posts = self::getPosts();
        $threads = self::getThreads();
        
        $post_id = uniqid('post_', true);
        $post = [
            'id' => $post_id,
            'author' => $author,
            'content' => Security::sanitize($content),
            'timestamp' => time(),
            'likes' => [],
            'like_count' => 0,
            'thread_id' => $thread_id,
            'is_thread_starter' => false
        ];
        
        if (empty($thread_id)) {
            $thread_id = uniqid('thread_', true);
            $thread_title = mb_strlen($content) > 100 ? mb_substr($content, 0, 100) . '...' : $content;
            
            $thread = [
                'id' => $thread_id,
                'title' => Security::e($thread_title),
                'author' => $author,
                'created' => time(),
                'last_activity' => time(),
                'post_count' => 1,
                'is_locked' => false,
                'is_pinned' => false
            ];
            
            array_unshift($threads, $thread);
            $post['is_thread_starter'] = true;
            $post['thread_id'] = $thread_id;
        } else {
            $thread_found = false;
            foreach ($threads as &$thread) {
                if ($thread['id'] === $thread_id) {
                    $thread['last_activity'] = time();
                    $thread['post_count'] = ($thread['post_count'] ?? 0) + 1;
                    $thread_found = true;
                    break;
                }
            }
            
            if (!$thread_found) {
                return false;
            }
        }
        
        $post['thread_id'] = $thread_id;
        array_unshift($posts, $post);
        
        self::savePosts($posts);
        self::saveThreads($threads);
        
        return [
            'post' => $post,
            'thread_id' => $thread_id,
            'post_id' => $post_id
        ];
    }
}

class ClanManager {
    public static function getClans() {
        return c(a()['data_path'] . a()['files']['clans'], []);
    }
    
    public static function saveClans($clans) {
        return d(a()['data_path'] . a()['files']['clans'], $clans);
    }
    
    public static function getClan($clan_id) {
        $clans = self::getClans();
        foreach ($clans as $clan) {
            if ($clan['id'] === $clan_id) {
                return $clan;
            }
        }
        return null;
    }
    
    public static function createClan($name, $tag, $description, $creator) {
        $clans = self::getClans();
        $users = UserManager::getUsers();
        
        foreach ($clans as $clan) {
            if ($clan['name'] === $name || $clan['tag'] === $tag) {
                return false;
            }
        }
        
        $cfg = a()['config'];
        if (!isset($users[$creator]) || $users[$creator]['crystals'] < $cfg['clan_cost']) {
            return false;
        }
        
        $clan_id = uniqid('clan_', true);
        $clan = [
            'id' => $clan_id,
            'name' => Security::sanitize($name),
            'tag' => strtoupper(Security::sanitize($tag)),
            'description' => Security::sanitize($description),
            'creator' => $creator,
            'created' => time(),
            'members' => [$creator],
            'member_count' => 1,
            'toasters' => 0,
            'level' => 1,
            'experience' => 0,
            'join_requests' => [],
            'settings' => [
                'public' => true,
                'auto_accept' => false,
                'min_level' => 0
            ],
            'stats' => [
                'total_posts' => 0,
                'total_likes' => 0,
                'total_contributions' => 0
            ]
        ];
        
        $clans[] = $clan;
        
        $users[$creator]['crystals'] -= $cfg['clan_cost'];
        $users[$creator]['clan_id'] = $clan_id;
        $users[$creator]['clan_role'] = 'leader';
        
        UserManager::saveUsers($users);
        return self::saveClans($clans);
    }
    
    public static function joinClan($clan_id, $username) {
        $clans = self::getClans();
        $users = UserManager::getUsers();
        
        if (!isset($users[$username])) {
            return false;
        }
        
        $user = $users[$username];
        
        if (!empty($user['clan_id'])) {
            return false;
        }
        
        $clan_found = false;
        $cfg = a()['config'];
        
        foreach ($clans as &$clan) {
            if ($clan['id'] === $clan_id) {
                $clan_found = true;
                
                if (count($clan['members']) >= $cfg['max_clan']) {
                    return false;
                }
                
                if (in_array($username, $clan['members'])) {
                    return false;
                }
                
                if ($clan['settings']['auto_accept'] === true) {
                    $clan['members'][] = $username;
                    $clan['member_count'] = count($clan['members']);
                    
                    $users[$username]['clan_id'] = $clan_id;
                    $users[$username]['clan_role'] = 'member';
                    
                    if (!UserManager::saveUsers($users)) {
                        return false;
                    }
                    
                    if (($key = array_search($username, $clan['join_requests'])) !== false) {
                        unset($clan['join_requests'][$key]);
                        $clan['join_requests'] = array_values($clan['join_requests']);
                    }
                } else {
                    if (!in_array($username, $clan['join_requests'])) {
                        $clan['join_requests'][] = $username;
                    }
                }
                
                break;
            }
        }
        
        if (!$clan_found) {
            return false;
        }
        
        return self::saveClans($clans);
    }
    
    public static function acceptJoinRequest($clan_id, $username, $admin_username) {
        $clans = self::getClans();
        $users = UserManager::getUsers();
        
        foreach ($clans as &$clan) {
            if ($clan['id'] === $clan_id) {
                $admin_data = $users[$admin_username] ?? null;
                if (!$admin_data || $admin_data['clan_id'] !== $clan_id || 
                    !in_array($admin_data['clan_role'], ['leader', 'co-leader'])) {
                    return false;
                }
                
                $key = array_search($username, $clan['join_requests']);
                if ($key === false) {
                    return false;
                }
                
                unset($clan['join_requests'][$key]);
                $clan['join_requests'] = array_values($clan['join_requests']);
                
                if (!in_array($username, $clan['members'])) {
                    $clan['members'][] = $username;
                    $clan['member_count']++;
                    
                    $users[$username]['clan_id'] = $clan_id;
                    $users[$username]['clan_role'] = 'member';
                    
                    UserManager::saveUsers($users);
                }
                
                break;
            }
        }
        
        return self::saveClans($clans);
    }
    
    public static function rejectJoinRequest($clan_id, $username, $admin_username) {
        $clans = self::getClans();
        $users = UserManager::getUsers();
        
        foreach ($clans as &$clan) {
            if ($clan['id'] === $clan_id) {
                $admin_data = $users[$admin_username] ?? null;
                if (!$admin_data || $admin_data['clan_id'] !== $clan_id || 
                    !in_array($admin_data['clan_role'], ['leader', 'co-leader'])) {
                    return false;
                }
                
                $key = array_search($username, $clan['join_requests']);
                if ($key === false) {
                    return false;
                }
                
                unset($clan['join_requests'][$key]);
                $clan['join_requests'] = array_values($clan['join_requests']);
                break;
            }
        }
        
        return self::saveClans($clans);
    }
    
    public static function leaveClan($username) {
        $users = UserManager::getUsers();
        if (!isset($users[$username]) || empty($users[$username]['clan_id'])) {
            return false;
        }
        
        $clan_id = $users[$username]['clan_id'];
        $clans = self::getClans();
        $clan_updated = false;
        
        foreach ($clans as &$clan) {
            if ($clan['id'] === $clan_id) {
                $key = array_search($username, $clan['members']);
                if ($key !== false) {
                    unset($clan['members'][$key]);
                    $clan['members'] = array_values($clan['members']);
                    $clan['member_count']--;
                    
                    if ($users[$username]['clan_role'] === 'leader' && !empty($clan['members'])) {
                        $new_leader = $clan['members'][0];
                        $users[$new_leader]['clan_role'] = 'leader';
                        $clan['creator'] = $new_leader;
                    }
                    
                    $clan_updated = true;
                }
                break;
            }
        }
        
        if ($clan_updated) {
            $users[$username]['clan_id'] = null;
            $users[$username]['clan_role'] = null;
            
            UserManager::saveUsers($users);
            return self::saveClans($clans);
        }
        
        return false;
    }
    
    public static function addContribution($username, $amount) {
        $users = UserManager::getUsers();
        if (!isset($users[$username]) || empty($users[$username]['clan_id'])) {
            return false;
        }
        
        if ($users[$username]['toasters'] < $amount || $amount <= 0) {
            return false;
        }
        
        $clan_id = $users[$username]['clan_id'];
        $clans = self::getClans();
        
        foreach ($clans as &$clan) {
            if ($clan['id'] === $clan_id) {
                $clan['toasters'] += $amount;
                $clan['stats']['total_contributions'] += $amount;
                
                $experience = floor($amount / 10);
                $clan['experience'] += $experience;
                
                while ($clan['experience'] >= ($clan['level'] * 1000)) {
                    $clan['experience'] -= ($clan['level'] * 1000);
                    $clan['level']++;
                }
                
                if (!isset($users[$username]['stats']['clan_contributions'])) {
                    $users[$username]['stats']['clan_contributions'] = 0;
                }
                $users[$username]['stats']['clan_contributions'] += $amount;
                $users[$username]['toasters'] -= $amount;
                
                UserManager::saveUsers($users);
                break;
            }
        }
        
        return self::saveClans($clans);
    }
    
    public static function getClanLeaderboard($limit = 10) {
        $clans = self::getClans();
        $leaderboard = [];
        
        foreach ($clans as $clan) {
            $score = ($clan['level'] * 1000) + 
                    ($clan['toasters'] / 10) +
                    ($clan['stats']['total_contributions'] / 5) +
                    ($clan['member_count'] * 50);
            $leaderboard[$clan['id']] = $score;
        }
        
        arsort($leaderboard);
        $result = [];
        $i = 0;
        foreach ($leaderboard as $clan_id => $score) {
            if ($i >= $limit) break;
            $clan = self::getClan($clan_id);
            if ($clan) {
                $result[] = $clan;
                $i++;
            }
        }
        
        return $result;
    }
}

class TradeManager {
    const STATUS_PENDING = 'pending';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_REJECTED = 'rejected';
    const STATUS_CANCELLED = 'cancelled';
    
    public static function getTrades() {
        return c(a()['data_path'] . a()['files']['trades'], []);
    }
    
    public static function saveTrades($trades) {
        return d(a()['data_path'] . a()['files']['trades'], $trades);
    }
    
    public static function createTrade($initiator, $recipient, $offer_items, $request_items, $offer_toasters = 0, $request_toasters = 0) {
        $trades = self::getTrades();
        
        $initiator_data = UserManager::getUser($initiator);
        $recipient_data = UserManager::getUser($recipient);
        
        if (!$initiator_data || !$recipient_data) {
            return ['success' => false, 'message' => 'User not found'];
        }
        
        if ($initiator === $recipient) {
            return ['success' => false, 'message' => 'Cannot trade with yourself'];
        }
        
        foreach ($offer_items as $category => $item_id) {
            if (!in_array($item_id, $initiator_data['inventory'][$category] ?? [])) {
                return ['success' => false, 'message' => "You don't have $item_id"];
            }
        }
        
        if ($offer_toasters > $initiator_data['toasters']) {
            return ['success' => false, 'message' => 'Not enough toasters'];
        }
        
        foreach ($request_items as $category => $item_id) {
            if (!in_array($item_id, $recipient_data['inventory'][$category] ?? [])) {
                return ['success' => false, 'message' => "Recipient doesn't have $item_id"];
            }
        }
        
        $trade_id = uniqid('trade_', true);
        $trade = [
            'id' => $trade_id,
            'initiator' => $initiator,
            'recipient' => $recipient,
            'offer_items' => $offer_items,
            'request_items' => $request_items,
            'offer_toasters' => $offer_toasters,
            'request_toasters' => $request_toasters,
            'status' => self::STATUS_PENDING,
            'created_at' => time(),
            'updated_at' => time()
        ];
        
        $trades[] = $trade;
        self::saveTrades($trades);
        
        return ['success' => true, 'trade_id' => $trade_id];
    }
    
    public static function acceptTrade($trade_id, $username) {
        $trades = self::getTrades();
        
        foreach ($trades as &$trade) {
            if ($trade['id'] === $trade_id) {
                if ($trade['recipient'] !== $username) {
                    return ['success' => false, 'message' => 'Not authorized'];
                }
                
                if ($trade['status'] !== self::STATUS_PENDING) {
                    return ['success' => false, 'message' => 'Trade is not pending'];
                }
                
                $initiator_data = UserManager::getUser($trade['initiator']);
                $recipient_data = UserManager::getUser($trade['recipient']);
                
                foreach ($trade['offer_items'] as $category => $item_id) {
                    if (!in_array($item_id, $initiator_data['inventory'][$category] ?? [])) {
                        return ['success' => false, 'message' => 'Initiator no longer has the items'];
                    }
                }
                
                foreach ($trade['request_items'] as $category => $item_id) {
                    if (!in_array($item_id, $recipient_data['inventory'][$category] ?? [])) {
                        return ['success' => false, 'message' => 'You no longer have the items'];
                    }
                }
                
                if ($trade['offer_toasters'] > $initiator_data['toasters']) {
                    return ['success' => false, 'message' => 'Initiator no longer has enough toasters'];
                }
                
                if ($trade['request_toasters'] > $recipient_data['toasters']) {
                    return ['success' => false, 'message' => 'You no longer have enough toasters'];
                }
                
                foreach ($trade['offer_items'] as $category => $item_id) {
                    $key = array_search($item_id, $initiator_data['inventory'][$category]);
                    if ($key !== false) {
                        unset($initiator_data['inventory'][$category][$key]);
                    }
                    $initiator_data['inventory'][$category] = array_values($initiator_data['inventory'][$category]);
                    $recipient_data['inventory'][$category][] = $item_id;
                }
                
                foreach ($trade['request_items'] as $category => $item_id) {
                    $key = array_search($item_id, $recipient_data['inventory'][$category]);
                    if ($key !== false) {
                        unset($recipient_data['inventory'][$category][$key]);
                    }
                    $recipient_data['inventory'][$category] = array_values($recipient_data['inventory'][$category]);
                    $initiator_data['inventory'][$category][] = $item_id;
                }
                
                $initiator_data['toasters'] -= $trade['offer_toasters'];
                $initiator_data['toasters'] += $trade['request_toasters'];
                $recipient_data['toasters'] -= $trade['request_toasters'];
                $recipient_data['toasters'] += $trade['offer_toasters'];
                
                $initiator_data['stats']['toasters_earned'] += $trade['request_toasters'];
                $recipient_data['stats']['toasters_earned'] += $trade['offer_toasters'];
                
                UserManager::updateUser($trade['initiator'], $initiator_data);
                UserManager::updateUser($trade['recipient'], $recipient_data);
                
                $trade['status'] = self::STATUS_ACCEPTED;
                $trade['updated_at'] = time();
                self::saveTrades($trades);
                
                return ['success' => true, 'message' => 'Trade completed successfully!'];
            }
        }
        
        return ['success' => false, 'message' => 'Trade not found'];
    }
    
    public static function rejectTrade($trade_id, $username) {
        $trades = self::getTrades();
        
        foreach ($trades as &$trade) {
            if ($trade['id'] === $trade_id) {
                if ($trade['recipient'] !== $username) {
                    return ['success' => false, 'message' => 'Not authorized'];
                }
                
                $trade['status'] = self::STATUS_REJECTED;
                $trade['updated_at'] = time();
                self::saveTrades($trades);
                
                return ['success' => true, 'message' => 'Trade rejected'];
            }
        }
        
        return ['success' => false, 'message' => 'Trade not found'];
    }
    
    public static function cancelTrade($trade_id, $username) {
        $trades = self::getTrades();
        
        foreach ($trades as &$trade) {
            if ($trade['id'] === $trade_id) {
                if ($trade['initiator'] !== $username) {
                    return ['success' => false, 'message' => 'Not authorized'];
                }
                
                $trade['status'] = self::STATUS_CANCELLED;
                $trade['updated_at'] = time();
                self::saveTrades($trades);
                
                return ['success' => true, 'message' => 'Trade cancelled'];
            }
        }
        
        return ['success' => false, 'message' => 'Trade not found'];
    }
    
    public static function getUserTrades($username) {
        $trades = self::getTrades();
        $user_trades = [];
        
        foreach ($trades as $trade) {
            if ($trade['initiator'] === $username || $trade['recipient'] === $username) {
                $user_trades[] = $trade;
            }
        }
        
        return $user_trades;
    }
    
    public static function getPendingTradesForUser($username) {
        $trades = self::getTrades();
        $pending = [];
        
        foreach ($trades as $trade) {
            if ($trade['status'] === self::STATUS_PENDING && $trade['recipient'] === $username) {
                $pending[] = $trade;
            }
        }
        
        return $pending;
    }
    
    public static function getTrade($trade_id) {
        $trades = self::getTrades();
        
        foreach ($trades as $trade) {
            if ($trade['id'] === $trade_id) {
                return $trade;
            }
        }
        
        return null;
    }
}

class Shop {
    private static $items = [
        'name_colors' => [
            'default' => ['name' => 'Default', 'color' => '#e67e22', 'price' => 0, 'currency' => 'toasters'],
            'blue' => ['name' => 'Blue', 'color' => '#3498db', 'price' => 50, 'currency' => 'toasters'],
            'red' => ['name' => 'Red', 'color' => '#e74c3c', 'price' => 50, 'currency' => 'toasters'],
            'green' => ['name' => 'Green', 'color' => '#2ecc71', 'price' => 50, 'currency' => 'toasters'],
            'purple' => ['name' => 'Purple', 'color' => '#9b59b6', 'price' => 100, 'currency' => 'toasters'],
            'gold' => ['name' => 'Gold', 'color' => '#f1c40f', 'price' => 200, 'currency' => 'crystals'],
            'rainbow' => ['name' => 'Rainbow', 'color' => 'linear-gradient(45deg, #ff0000, #ff9900, #ffff00, #00ff00, #00ffff, #0000ff, #9900ff)', 'price' => 500, 'currency' => 'crystals'],
            'neon' => ['name' => 'Neon Pink', 'color' => '#ff00ff', 'price' => 300, 'currency' => 'crystals'],
            'ice' => ['name' => 'Ice Blue', 'color' => '#00ffff', 'price' => 150, 'currency' => 'toasters'],
            'shadow' => ['name' => 'Shadow', 'color' => '#2c3e50', 'price' => 100, 'currency' => 'toasters']
        ],
        'badges' => [
            'none' => ['name' => 'No Badge', 'icon' => '', 'price' => 0, 'currency' => 'toasters'],
            'star' => ['name' => 'Star', 'icon' => '⭐', 'price' => 100, 'currency' => 'toasters'],
            'fire' => ['name' => 'Fire', 'icon' => '🔥', 'price' => 150, 'currency' => 'toasters'],
            'heart' => ['name' => 'Heart', 'icon' => '❤️', 'price' => 200, 'currency' => 'toasters'],
            'crown' => ['name' => 'Crown', 'icon' => '👑', 'price' => 500, 'currency' => 'crystals'],
            'rocket' => ['name' => 'Rocket', 'icon' => '🚀', 'price' => 300, 'currency' => 'toasters'],
            'shield' => ['name' => 'Shield', 'icon' => '🛡️', 'price' => 250, 'currency' => 'toasters'],
            'dragon' => ['name' => 'Dragon', 'icon' => '🐉', 'price' => 600, 'currency' => 'crystals'],
            'robot' => ['name' => 'Robot', 'icon' => '🤖', 'price' => 400, 'currency' => 'crystals'],
            'ghost' => ['name' => 'Ghost', 'icon' => '👻', 'price' => 350, 'currency' => 'toasters']
        ],
        'titles' => [
            'newbie' => ['name' => 'Newbie', 'text' => '🌱 Newbie', 'price' => 0, 'currency' => 'toasters'],
            'member' => ['name' => 'Member', 'text' => '👤 Member', 'price' => 100, 'currency' => 'toasters'],
            'veteran' => ['name' => 'Veteran', 'text' => '⭐ Veteran', 'price' => 300, 'currency' => 'toasters'],
            'legend' => ['name' => 'Legend', 'text' => '🏆 Legend', 'price' => 1000, 'currency' => 'crystals'],
            'elite' => ['name' => 'Elite', 'text' => '💎 Elite', 'price' => 500, 'currency' => 'crystals'],
            'master' => ['name' => 'Master', 'text' => '🎯 Master', 'price' => 800, 'currency' => 'crystals'],
            'toast_lover' => ['name' => 'Toast Lover', 'text' => '🍞 Toast Lover', 'price' => 200, 'currency' => 'toasters'],
            'toast_master' => ['name' => 'Toast Master', 'text' => '🥪 Toast Master', 'price' => 400, 'currency' => 'toasters'],
            'bread_winner' => ['name' => 'Bread Winner', 'text' => '🥖 Bread Winner', 'price' => 300, 'currency' => 'toasters'],
            'jam_king' => ['name' => 'Jam King', 'text' => '🍓 Jam King', 'price' => 350, 'currency' => 'toasters'],
            'butter_baron' => ['name' => 'Butter Baron', 'text' => '🧈 Butter Baron', 'price' => 250, 'currency' => 'toasters'],
            'toaster_hero' => ['name' => 'Toaster Hero', 'text' => '⚡ Toaster Hero', 'price' => 600, 'currency' => 'crystals'],
            'crunchy_crust' => ['name' => 'Crunchy Crust', 'text' => '🥐 Crunchy Crust', 'price' => 150, 'currency' => 'toasters'],
            'warm_bread' => ['name' => 'Warm Bread', 'text' => '🌡️ Warm Bread', 'price' => 180, 'currency' => 'toasters'],
            'golden_crust' => ['name' => 'Golden Crust', 'text' => '✨ Golden Crust', 'price' => 450, 'currency' => 'crystals'],
            'bread_sage' => ['name' => 'Bread Sage', 'text' => '🧙‍♂️ Bread Sage', 'price' => 700, 'currency' => 'crystals'],
            'gamer' => ['name' => 'Gamer', 'text' => '🎮 Gamer', 'price' => 200, 'currency' => 'toasters'],
            'streamer' => ['name' => 'Streamer', 'text' => '📺 Streamer', 'price' => 350, 'currency' => 'toasters'],
            'speedrunner' => ['name' => 'Speedrunner', 'text' => '⏱️ Speedrunner', 'price' => 500, 'currency' => 'crystals'],
            'completionist' => ['name' => 'Completionist', 'text' => '✅ Completionist', 'price' => 600, 'currency' => 'crystals'],
            'arcade_champ' => ['name' => 'Arcade Champ', 'text' => '🕹️ Arcade Champ', 'price' => 400, 'currency' => 'toasters'],
            'boss_slayer' => ['name' => 'Boss Slayer', 'text' => '🗡️ Boss Slayer', 'price' => 550, 'currency' => 'crystals'],
            'loot_finder' => ['name' => 'Loot Finder', 'text' => '💎 Loot Finder', 'price' => 300, 'currency' => 'toasters'],
            'pvp_warrior' => ['name' => 'PVP Warrior', 'text' => '⚔️ PVP Warrior', 'price' => 450, 'currency' => 'crystals'],
            'quest_master' => ['name' => 'Quest Master', 'text' => '📜 Quest Master', 'price' => 400, 'currency' => 'toasters'],
            'level_100' => ['name' => 'Level 100', 'text' => '💯 Level 100', 'price' => 800, 'currency' => 'crystals'],
            'clan_elder' => ['name' => 'Clan Elder', 'text' => '👴 Clan Elder', 'price' => 300, 'currency' => 'toasters'],
            'war_chief' => ['name' => 'War Chief', 'text' => '🛡️ War Chief', 'price' => 600, 'currency' => 'crystals'],
            'guild_master' => ['name' => 'Guild Master', 'text' => '🏛️ Guild Master', 'price' => 700, 'currency' => 'crystals'],
            'alliance_leader' => ['name' => 'Alliance Leader', 'text' => '🤝 Alliance Leader', 'price' => 500, 'currency' => 'crystals'],
            'raid_leader' => ['name' => 'Raid Leader', 'text' => '🎯 Raid Leader', 'price' => 400, 'currency' => 'crystals'],
            'treasury_keeper' => ['name' => 'Treasury Keeper', 'text' => '💰 Treasury Keeper', 'price' => 350, 'currency' => 'toasters'],
            'recruiter' => ['name' => 'Recruiter', 'text' => '📢 Recruiter', 'price' => 250, 'currency' => 'toasters'],
            'strategist' => ['name' => 'Strategist', 'text' => '🧠 Strategist', 'price' => 450, 'currency' => 'crystals'],
            'diplomat' => ['name' => 'Diplomat', 'text' => '🕊️ Diplomat', 'price' => 300, 'currency' => 'toasters'],
            'guardian' => ['name' => 'Guardian', 'text' => '🛡️ Guardian', 'price' => 400, 'currency' => 'toasters'],
            'procrastinator' => ['name' => 'Procrastinator', 'text' => '⏳ Procrastinator', 'price' => 150, 'currency' => 'toasters'],
            'coffee_addict' => ['name' => 'Coffee Addict', 'text' => '☕ Coffee Addict', 'price' => 200, 'currency' => 'toasters'],
            'meme_lord' => ['name' => 'Meme Lord', 'text' => '🦸‍♂️ Meme Lord', 'price' => 350, 'currency' => 'toasters'],
            'night_owl' => ['name' => 'Night Owl', 'text' => '🦉 Night Owl', 'price' => 250, 'currency' => 'toasters'],
            'early_bird' => ['name' => 'Early Bird', 'text' => '🐦 Early Bird', 'price' => 200, 'currency' => 'toasters'],
            'cat_person' => ['name' => 'Cat Person', 'text' => '🐱 Cat Person', 'price' => 300, 'currency' => 'toasters'],
            'dog_person' => ['name' => 'Dog Person', 'text' => '🐶 Dog Person', 'price' => 300, 'currency' => 'toasters'],
            'keyboard_warrior' => ['name' => 'Keyboard Warrior', 'text' => '⌨️ Keyboard Warrior', 'price' => 180, 'currency' => 'toasters'],
            'forum_dweller' => ['name' => 'Forum Dweller', 'text' => '🏠 Forum Dweller', 'price' => 220, 'currency' => 'toasters'],
            'ghost_writer' => ['name' => 'Ghost Writer', 'text' => '👻 Ghost Writer', 'price' => 400, 'currency' => 'crystals'],
            'immortal' => ['name' => 'Immortal', 'text' => '♾️ Immortal', 'price' => 1500, 'currency' => 'crystals'],
            'phoenix' => ['name' => 'Phoenix', 'text' => '🔥 Phoenix', 'price' => 1200, 'currency' => 'crystals'],
            'dragonlord' => ['name' => 'Dragonlord', 'text' => '🐲 Dragonlord', 'price' => 2000, 'currency' => 'crystals'],
            'time_traveler' => ['name' => 'Time Traveler', 'text' => '⏰ Time Traveler', 'price' => 1300, 'currency' => 'crystals'],
            'cosmic_being' => ['name' => 'Cosmic Being', 'text' => '🌌 Cosmic Being', 'price' => 1800, 'currency' => 'crystals'],
            'digital_ghost' => ['name' => 'Digital Ghost', 'text' => '👾 Digital Ghost', 'price' => 900, 'currency' => 'crystals'],
            'cyborg' => ['name' => 'Cyborg', 'text' => '🔧 Cyborg', 'price' => 1100, 'currency' => 'crystals'],
            'wizard' => ['name' => 'Wizard', 'text' => '🧙‍♂️ Wizard', 'price' => 800, 'currency' => 'crystals'],
            'ninja' => ['name' => 'Ninja', 'text' => '🥷 Ninja', 'price' => 700, 'currency' => 'crystals'],
            'pirate' => ['name' => 'Pirate', 'text' => '🏴‍☠️ Pirate', 'price' => 650, 'currency' => 'crystals'],
            'samurai' => ['name' => 'Samurai', 'text' => '🗡️ Samurai', 'price' => 750, 'currency' => 'crystals'],
            'viking' => ['name' => 'Viking', 'text' => '🛡️ Viking', 'price' => 600, 'currency' => 'crystals'],
            'spartan' => ['name' => 'Spartan', 'text' => '⚔️ Spartan', 'price' => 550, 'currency' => 'crystals']
        ],
        'profileicons' => [
            'default' => ['name' => 'Default', 'image' => '', 'price' => 0, 'currency' => 'toasters'],
            'durr' => ['name' => 'Durr', 'image' => 'durr.jpg', 'price' => 100, 'currency' => 'toasters'],
            'kotek' => ['name' => 'Cat123', 'image' => 'kotek.jpg', 'price' => 250, 'currency' => 'toasters'],
            'kuki' => ['name' => 'Kuki', 'image' => 'kuki.jpg', 'price' => 500, 'currency' => 'toasters'],
        ]
    ];
    
    public static function getItems($category = null) {
        $items = $category ? (self::$items[$category] ?? []) : self::$items;
        return $items;
    }
    
    public static function purchase($username, $category, $item_id) {
        $user = UserManager::getUser($username);
        if (!$user) return false;
        
        $items = self::getItems($category);
        if (!isset($items[$item_id])) return false;
        
        $item = $items[$item_id];
        
        if ($item['price'] <= 0) {
            if ($category === 'name_colors' || $category === 'badges' || $category === 'titles') {
                if (!in_array($item_id, $user['inventory'][$category])) {
                    $user['inventory'][$category][] = $item_id;
                    UserManager::updateUser($username, $user);
                }
            }
            return true;
        }
        
        $currency = $item['currency'];
        
        if ($user[$currency] < $item['price']) {
            return false;
        }
        
        $user[$currency] -= $item['price'];
        $user['stats']['purchases']++;
        
        if (!in_array($item_id, $user['inventory'][$category])) {
            $user['inventory'][$category][] = $item_id;
        }
        
        return UserManager::updateUser($username, $user);
    }
}

class AchievementSystem {
    private static $achievements = [
        'first_post' => [
            'name' => 'First Steps',
            'desc' => 'Create your first post',
            'icon' => '👣',
            'reward' => ['toasters' => 50],
            'condition' => 'posts >= 1'
        ],
        '10_posts' => [
            'name' => 'Chatterbox',
            'desc' => 'Make 10 posts',
            'icon' => '💬',
            'reward' => ['toasters' => 100],
            'condition' => 'posts >= 10'
        ],
        '50_posts' => [
            'name' => 'Forum Veteran',
            'desc' => 'Make 50 posts',
            'icon' => '📚',
            'reward' => ['toasters' => 500],
            'condition' => 'posts >= 50'
        ],
        '100_posts' => [
            'name' => 'Forum Legend',
            'desc' => 'Make 100 posts',
            'icon' => '🏆',
            'reward' => ['toasters' => 1000],
            'condition' => 'posts >= 100'
        ],
        'first_thread' => [
            'name' => 'Thread Starter',
            'desc' => 'Create your first thread',
            'icon' => '📋',
            'reward' => ['toasters' => 100],
            'condition' => 'threads >= 1'
        ],
        '10_threads' => [
            'name' => 'Discussion Leader',
            'desc' => 'Create 10 threads',
            'icon' => '👑',
            'reward' => ['toasters' => 300],
            'condition' => 'threads >= 10'
        ],
        'first_like' => [
            'name' => 'First Impression',
            'desc' => 'Receive your first like',
            'icon' => '👍',
            'reward' => ['toasters' => 30],
            'condition' => 'likes_received >= 1'
        ],
        '10_likes' => [
            'name' => 'Popular User',
            'desc' => 'Receive 10 likes',
            'icon' => '⭐',
            'reward' => ['toasters' => 150],
            'condition' => 'likes_received >= 10'
        ],
        '50_likes' => [
            'name' => 'Community Favorite',
            'desc' => 'Receive 50 likes',
            'icon' => '❤️',
            'reward' => ['toasters' => 500],
            'condition' => 'likes_received >= 50'
        ],
        '100_likes' => [
            'name' => 'Like Magnet',
            'desc' => 'Receive 100 likes',
            'icon' => '🧲',
            'reward' => ['toasters' => 1000],
            'condition' => 'likes_received >= 100'
        ],
        'liker' => [
            'name' => 'Supportive Member',
            'desc' => 'Give 10 likes to others',
            'icon' => '🤝',
            'reward' => ['toasters' => 100],
            'condition' => 'likes_given >= 10'
        ],
        'active_liker' => [
            'name' => 'Active Supporter',
            'desc' => 'Give 50 likes to others',
            'icon' => '🙌',
            'reward' => ['toasters' => 300],
            'condition' => 'likes_given >= 50'
        ],
        '7_day_streak' => [
            'name' => 'Weekly Warrior',
            'desc' => 'Login for 7 consecutive days',
            'icon' => '🗓️',
            'reward' => ['toasters' => 200],
            'condition' => 'consecutive_days >= 7'
        ],
        '30_day_streak' => [
            'name' => 'Monthly Master',
            'desc' => 'Login for 30 consecutive days',
            'icon' => '📅',
            'reward' => ['toasters' => 1000],
            'condition' => 'consecutive_days >= 30'
        ],
        'shopaholic' => [
            'name' => 'Shopaholic',
            'desc' => 'Purchase 5 different items',
            'icon' => '🛍️',
            'reward' => ['toasters' => 200],
            'condition' => 'purchases >= 5'
        ],
        'collector' => [
            'name' => 'Collector',
            'desc' => 'Unlock 10 different cosmetics',
            'icon' => '📦',
            'reward' => ['toasters' => 500],
            'condition' => 'cosmetics >= 10'
        ],
        'social_butterfly' => [
            'name' => 'Social Butterfly',
            'desc' => 'Add 10 friends',
            'icon' => '🦋',
            'reward' => ['toasters' => 1000],
            'condition' => 'friends_count >= 10'
        ],
        'clan_leader' => [
            'name' => 'Clan Leader',
            'desc' => 'Create your own clan',
            'icon' => '⚔️',
            'reward' => ['toasters' => 1500],
            'condition' => 'clan_created'
        ],
        'team_player' => [
            'name' => 'Team Player',
            'desc' => 'Contribute 1000 toasters to your clan',
            'icon' => '🤝',
            'reward' => ['toasters' => 800],
            'condition' => 'clan_contributions >= 1000'
        ],
        'verified' => [
            'name' => 'Verified User',
            'desc' => 'Get verified to unlock music',
            'icon' => '✅',
            'reward' => ['crystals' => 100, 'toasters' => 500],
            'condition' => 'is_verified'
        ]
    ];

    
    public static function getAll() {
        return self::$achievements;
    }
    
    public static function checkAchievements($username) {
        $user = UserManager::getUser($username);
        if (!$user) return [];
        
        $unlocked = [];
        $stats = $user['stats'];
        $inventory = $user['inventory'];
        
        $cosmetics_count = 0;
        $cosmetics_count += count($inventory['name_colors'] ?? []) - 1;
        $cosmetics_count += count($inventory['badges'] ?? []) - 1;
        $cosmetics_count += count($inventory['titles'] ?? []) - 1;
        
        foreach (self::$achievements as $id => $achievement) {
            if (in_array($id, $user['achievements'] ?? [])) continue;
            
            $unlock = false;
            
            switch ($achievement['condition']) {
                case 'posts >= 1': $unlock = $stats['posts'] >= 1; break;
                case 'posts >= 10': $unlock = $stats['posts'] >= 10; break;
                case 'posts >= 50': $unlock = $stats['posts'] >= 50; break;
                case 'posts >= 100': $unlock = $stats['posts'] >= 100; break;
                case 'threads >= 1': $unlock = $stats['threads'] >= 1; break;
                case 'threads >= 10': $unlock = $stats['threads'] >= 10; break;
                case 'likes_received >= 1': $unlock = $stats['likes_received'] >= 1; break;
                case 'likes_received >= 10': $unlock = $stats['likes_received'] >= 10; break;
                case 'likes_received >= 50': $unlock = $stats['likes_received'] >= 50; break;
                case 'likes_received >= 100': $unlock = $stats['likes_received'] >= 100; break;
                case 'likes_given >= 10': $unlock = $stats['likes_given'] >= 10; break;
                case 'likes_given >= 50': $unlock = $stats['likes_given'] >= 50; break;
                case 'consecutive_days >= 7': $unlock = $stats['consecutive_days'] >= 7; break;
                case 'consecutive_days >= 30': $unlock = $stats['consecutive_days'] >= 30; break;
                case 'purchases >= 5': $unlock = $stats['purchases'] >= 5; break;
                case 'cosmetics >= 10': $unlock = $cosmetics_count >= 10; break;
                case 'friends_count >= 10': $unlock = $stats['friends_count'] >= 10; break;
                case 'clan_created': $unlock = ($user['clan_role'] ?? '') === 'leader'; break;
                case 'clan_contributions >= 1000': $unlock = $stats['clan_contributions'] >= 1000; break;
                case 'is_verified': $unlock = $user['is_verified'] ?? false; break;
                default: $unlock = false;
            }
            
            if ($unlock) {
                $user['achievements'][] = $id;
                if (isset($achievement['reward']['toasters'])) {
                    $user['toasters'] += $achievement['reward']['toasters'];
                    $user['stats']['toasters_earned'] += $achievement['reward']['toasters'];
                }
                if (isset($achievement['reward']['crystals'])) {
                    $user['crystals'] += $achievement['reward']['crystals'];
                }
                $unlocked[] = $achievement['name'];
            }
        }
        
        if (!empty($unlocked)) {
            UserManager::updateUser($username, $user);
        }
        
        return $unlocked;
    }
    
    public static function syncAchievements($username) {
        $user = UserManager::getUser($username);
        if (!$user) return [];
        
        $unlocked = self::checkAchievements($username);
        return $unlocked;
    }
}


b(a()['data_path']);

?>

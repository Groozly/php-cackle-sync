<?php
/**
 * Cackle is a PHP class for comments sync and display
 *
 * PHP version 5
 *
 * @copyright 2013 Dmitry Elfimov
 * @license   http://www.elfimov.ru/nanobanano/license.txt MIT License
 * @link      https://github.com/Groozly/Cackle
 * 
 */
 
/**
 * Cackle class
 *
 * @package Cackle
 * @author  Dmitry Elfimov <elfimov@gmail.com>
 *
 * Example:
// make new PDO object
$pdo = new PDO('mysql:host=localhost;dbname=cackle;charset=cp1251', 'user', 'password');

$cackle = new Cackle(11111, $pdo, 'accountApiKey', 'siteApiKey', 0, 'cp1251');

// get Cackle code (JS and container) and comments from local DB (if any)
echo $cackle->showComments('cackletest');

// sync local comments with Cackle.me
$cackle->syncComments();

*/


class Cackle
{

    private $_commentsTable = 'cackle_comments';
    private $_commonTable = 'cackle_common';
    
    private $_pdo = null;

    public $siteId = null;
    
    public $accountApiKey = null;
    public $siteApiKey = null;
    
    /**
     * Constructor.
     *
     * @param integer $siteId        Cackle site ID
     * @param PDO     $pdo           PDO object
     * @param string  $accountApiKey Cackle Account API Key
     * @param string  $siteApiKey    Cackle Site API Key
     * @param integer $timer         Cron timer. If = 0, do not try to run cron 
     * while creating new object (recommended, sync comments manually).
     * If > 0, run comments sync every X second.
     * @param string  $cp            Local codepage
     */
    public function __construct(
        $siteId, 
        $pdo = false, 
        $accountApiKey = false, 
        $siteApiKey = false, 
        $timer = 0, 
        $cp = 'utf-8'
    ) {
        $this->siteId = $siteId;
        
        $this->_pdo = $pdo;
        
        $this->cp = $cp;
        
        $this->accountApiKey = $accountApiKey;
        $this->siteApiKey = $siteApiKey;
        
        if ($timer > 0 && $pdo !== false && $accountApiKey !== false && $siteApiKey !== false && $this->_isCron($timer)) {
            $this->syncComments();
        }
    }
    
    /**
     * Sync comments with cackle.me
     *
     * @return true on success, false on failure.
     */
    public function syncComments()
    {
        $return = false;
        $params = array(
            'accountApiKey' => $this->accountApiKey,
            'siteApiKey'    => $this->siteApiKey,
        );
        $result = $this->_pdo->prepare('SELECT `value` FROM `' . $this->_commonTable . '` WHERE `name` = "last_comment"');
        $result->execute();
        $row = $result->fetch();
        if (empty($row)) {
            $result = $this->_pdo->prepare('INSERT ' . $this->_commonTable . ' (`name`, `value`) VALUES ("last_comment", 0)');
            $result->execute();
        } else {
            $params['id'] = $row['value'];
        }
        $response = $this->_curl('cackle.me/api/comment/list?' . http_build_query($params));
        if ($response !== false) {
            $response = $this->_cackleJsonDecode($response);
            if (!empty($response)) {
                $return = $this->pushComments($response);
            }
        }
        return $return;
    }
    
    /**
     * Save comments to local DB.
     *
     * @param array $response decoded response of cackle.me API
     * 
     * @return true
     */
    public function pushComments($response)
    {
        if (empty($response['comments'])) {
            return false;
        }
        
        $findParent = $this->_pdo->prepare('SELECT id FROM `' . $this->_commentsTable . '` WHERE cackle_id=?');
        $updateLastComment = $this->_pdo->prepare('UPDATE `'  .$this->_commonTable . '` SET `value` = ? WHERE `name` = "last_comment"');
        $insertComment = $this->_pdo->prepare(
            'INSERT `' . $this->_commentsTable .'`'
            .' (cackle_id,   parent_id,  channel,  site_id,  author_id,  author_name,  author_email,  author_www,  author_avatar,  author_provider,  rating,  created,  ip,  message,  media,  status)'
            .' VALUES'
            .' (:cackle_id, :parent_id, :channel, :site_id, :author_id, :author_name, :author_email, :author_www, :author_avatar, :author_provider, :rating, :created, :ip, :message, :media, :status)'
        );
        
        foreach ($response['comments'] as $comment) {
            $status = $this->_getStatus($comment['status']);
            $author = empty($comment['author']) ? $this->_getAuthor($comment['anonym']) : $this->_getAuthor($comment['author']);
            
            $parentLocalId = 0;
            if (!empty($comment['parentId'])) {
                $findParent->bindValue(1, $comment['parentId'], PDO::PARAM_INT);
                $findParent->execute();
                $row = $findParent->fetch();
                if (!empty($row)) {
                    $parentLocalId = $row['id'];
                }
            }
            
            $insertComment->bindValue(':cackle_id',       $comment['id'], PDO::PARAM_INT);
            $insertComment->bindValue(':parent_id',       $parentLocalId, PDO::PARAM_INT);
            $insertComment->bindValue(':channel',         $this->_iconv($comment['channel']));
            $insertComment->bindValue(':site_id',         $comment['siteId'], PDO::PARAM_INT);
            $insertComment->bindValue(':author_id',       $author['id'], PDO::PARAM_INT);
            $insertComment->bindValue(':author_name',     $this->_iconv($author['name']));
            $insertComment->bindValue(':author_email',    $this->_iconv($author['email']));
            $insertComment->bindValue(':author_www',      $this->_iconv($author['www']));
            $insertComment->bindValue(':author_avatar',   $this->_iconv($author['avatar']));
            $insertComment->bindValue(':author_provider', $this->_iconv($author['provider']));
            $insertComment->bindValue(':rating',          $comment['rating'], PDO::PARAM_INT);
            $insertComment->bindValue(':created',         date('Y-m-d H:i:s', $comment['created']/1000));
            $insertComment->bindValue(':ip',              $comment['ip']);
            $insertComment->bindValue(':message',         $this->_iconv($comment['message']));
            $insertComment->bindValue(':media',           $this->_iconv($comment['media']));
            $insertComment->bindValue(':status',          $status, PDO::PARAM_INT);
            $insertComment->execute();

            $updateLastComment->execute(array($comment['id']));
        }
        return true;
    }
    
    /**
     * iconv wrapper.
     *
     * @param string $s string to convert according to local code page.
     * 
     * @return converted string or input without changes if local codepage is utf-8
     */
    private function _iconv($s) 
    {
        return strtolower($this->cp) != 'utf-8' ? iconv('utf-8', $this->cp, $s) : $s;
    }
    
    /**
     * Get author or anonym info based on cackle.
     * If provider is not set, but user is not anonymous, provider is cackle.
     *
     * @param array $author author or anonym info from cackle.me API
     * 
     * @return array with author info. 
     */
    private function _getAuthor($author) 
    {
        return array(
        'id'       => $author['id'],
        'name'     => $author['name'],
        'email'    => $author['email'],
        'www'      => empty($author['www']) ? null : $author['www'],
        'avatar'   => empty($author['avatar']) ? null : $author['avatar'],
        'provider' => isset($author['provider']) ? ($author['provider'] == '' ? 'cackle' : $author['provider']) : null,
        );
    }

    /**
     * Get status code by info from cackle.me API
     * Codes: 0 - pending, 1 - approved, 2 - rejected, 3 - spam, 4 - deleted
     *
     * @param string $status author or anonym info from cackle.me API
     * 
     * @return integer status code
     */
    private function _getStatus($status) 
    {
        switch (strtolower($status)) {
        case 'approved':
            $status = 1;
            break;
        case 'rejected':
            $status = 2;
            break;
        case 'spam':
            $status = 3;
            break;
        case 'deleted':
            $status = 4;
            break;
        case 'pending':
        default:
            $status = 0;
        }
        return $status;
    }

    /**
     * Check is it time to sync comments.
     *
     * @param integer $cronTime seconds. Cron period.
     * 
     * @return true if last sync was more then cronTime seconds ago, false otherwise.
     */
    private function _isCron($cronTime)
    {
        $result = $this->_pdo->prepare('SELECT `value` from ' . $this->_commonTable . ' WHERE `name` = "last_time"');
        $result->execute();
        $row = $result->fetch();
        if (empty($row)) {
            $result = $this->_pdo->prepare('INSERT `'  .$this->_commonTable . '` (`name`, `value`) VALUES ("last_time", '.time().')');
            $result->execute();
            return true;
        } else {
            if ($row['value'] + $cronTime > time()) {
                return false;
            } else {
                $result = $this->_pdo->prepare('UPDATE `'  .$this->_commonTable . '` SET `value` = '.time().' WHERE `name` = "last_time"');
                $result->execute();
                return true;
            }
        }
    }
    
    /**
     * curl wrapper.
     *
     * @param string $url get this url.
     * 
     * @return result on succes, FALSE on failure.
     */
    private function _curl($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; cackle.me comments sync)');  // "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.6) Gecko/20070725 Firefox/2.0.0.6"
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }
    
    /**
     * json wrapper.
     *
     * @param string $response string to decode.
     * 
     * @return result on succes, NULL on failure.
     */
    private function _cackleJsonDecode($response)
    {
        if (strpos($response, 'jQuery(') !== false) {
            $response_without_jquery = str_replace('jQuery(', '', $response);
            $response = str_replace(');', '', $response_without_jquery);
        }
        return json_decode($response, true);
    }
    
    /**
     * Create cackle container and comments for specified channel (HTML)
     *
     * @param string $channel cackle channel.
     * 
     * @return html to embed.
     */
    public function showComments($channel)
    { 
        $out = '';
        
        $out .= '
         <div id="mc-container">
            ' . $this->_getComments($this->siteId, $channel) . '
        </div>

        <script type="text/javascript">
        var mcSite = "' . $this->siteId . '";
        var mcChannel = "' . $channel .'";
        document.getElementById("mc-container").innerHTML = "";
        (function() {
            var mc = document.createElement("script");
            mc.type = "text/javascript";
            mc.async = true;
            mc.src = "//cackle.me/mc.widget-min.js";
            (document.getElementsByTagName("head")[0] || document.getElementsByTagName("body")[0]).appendChild(mc);
        })();
        </script>
        ';
        return $out;
    }
    
    /**
     * Create cackle comments HTML from local DB.
     *
     * @param array $channel cackle channel.
     * 
     * @return comments html.
     */
    private function _getComments($channel)
    {
        $out = '';
        if ($this->_pdo !== false) {
            $result = $this->_pdo->prepare('SELECT * FROM `' . $this->_commentsTable . '` WHERE `site_id` = :siteId AND `channel` = :channel AND `status` = 1');
            $result->execute(array(':channel' => $channel, ':siteId' => $this->siteId));
            while ($comment = $result->fetch()) {
                $out .= $this->_cackleComment($comment);
            }
            if (!empty($out)) {
                $out = '<div id="mc-content"><ul id="cackle-comments">' . $out . '</ul></div>';
            }
        }
        return $out;
    }
    
    /**
     * Create cackle comment HTML.
     *
     * @param array $comment cackle comment.
     * 
     * @return comment html.
     */
    private function _cackleComment($comment) 
    {
        $out = '';
        $out .= '<li id="cackle-comment-' . $comment['id'] . '">'
            .'<div id="cackle-comment-header-' . $comment['id'] . '" class="cackle-comment-header">'
            .'<cite id="cackle-cite-' . $comment['id'] . '">';
        if (empty($comment['author_provider'])) {
            $out .= '<span id="cackle-author-user-' . $comment['id'] . '">' . $comment['author_name'] . '</span>';
        } else {
            $out .= '<a id="cackle-author-user-' . $comment['id'] . '" href="' . $comment['author_www'] . '" rel="nofollow">' . $comment['author_name'] . '</a>';
        }
        $out .= '</cite>'
            .'</div>'
            .'<div id="cackle-comment-body-' . $comment['id'] . '" class="cackle-comment-body">'
                .'<div id="cackle-comment-message-' . $comment['id'] . '" class="cackle-comment-message">'
                    .$comment['message']
                .'</div>'
            .'</div>'
            .'</li>';
        return $out;
    }

}

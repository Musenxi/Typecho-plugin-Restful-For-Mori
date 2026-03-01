<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}
/**
 * Typecho Restful 插件
 *
 * @package Restful
 * @author MoeFront Studio
 * @version 1.2.0
 * @link https://moefront.github.io
 */
class Restful_Plugin implements Typecho_Plugin_Interface
{
    const ACTION_CLASS = 'Restful_Action';

    /**
     * 确保计数字段存在
     *
     * @return void
     */
    public static function ensureCounterColumns()
    {
        $db = Typecho_Db::get();
        $table = $db->getPrefix() . 'contents';

        self::ensureColumn($db, $table, 'viewsNum', "INT(10) UNSIGNED NOT NULL DEFAULT 0");
        self::ensureColumn($db, $table, 'likesNum', "INT(10) UNSIGNED NOT NULL DEFAULT 0");
    }

    /**
     * @param Typecho_Db $db
     * @param string $table
     * @param string $column
     * @param string $definition
     * @return void
     */
    private static function ensureColumn($db, $table, $column, $definition)
    {
        $exists = false;
        $safeColumn = addslashes($column);

        try {
            $rows = $db->fetchAll("SHOW COLUMNS FROM `{$table}` LIKE '{$safeColumn}'");
            $exists = is_array($rows) && count($rows) > 0;
        } catch (Exception $e) {
            $exists = false;
        }

        if ($exists) {
            return;
        }

        try {
            $db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        } catch (Exception $e) {
            // 忽略已存在或不支持 ALTER 的情况，避免影响插件初始化
        }
    }

    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        self::ensureCounterColumns();

        $routes = call_user_func(array(self::ACTION_CLASS, 'getRoutes'));
        foreach ($routes as $route) {
            Helper::addRoute($route['name'], $route['uri'], self::ACTION_CLASS, $route['action']);
        }
        Typecho_Plugin::factory('Widget_Feedback')->comment = array(__CLASS__, 'comment');
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array(__CLASS__, 'handlePostPublish');
        Typecho_Plugin::factory('Widget_Comments_Edit')->mark = array(__CLASS__, 'handleCommentMark');
        Typecho_Plugin::factory('index.php')->end = array(__CLASS__, 'handleRequestEnd');

        return '_(:з」∠)_';
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
    {
        $routes = call_user_func(array(self::ACTION_CLASS, 'getRoutes'));
        foreach ($routes as $route) {
            Helper::removeRoute($route['name']);
        }

        return '( ≧Д≦)';
    }

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        echo '<button type="button" class="btn" style="outline: 0" onclick="restfulUpgrade(this)">' . _t('检查并更新插件'). '</button>';

        $prefix = defined('__TYPECHO_RESTFUL_PREFIX__') ? __TYPECHO_RESTFUL_PREFIX__ : '/api/';
        /* API switcher */
        $routes = call_user_func(array(self::ACTION_CLASS, 'getRoutes'));
        echo '<h3>API 状态设置</h3>';

        foreach ($routes as $route) {
            if ($route['shortName'] == 'upgrade') {
                continue;
            }
            $tmp = new Typecho_Widget_Helper_Form_Element_Radio($route['shortName'], array(
                0 => _t('禁用'),
                1 => _t('启用'),
            ), 1, $route['uri'], _t($route['description']));
            $form->addInput($tmp);
        }
        /* cross-origin settings */
        $origin = new Typecho_Widget_Helper_Form_Element_Textarea('origin', null, null, _t('域名列表'), _t('一行一个<br>以下是例子qwq<br>http://localhost:8080<br>https://blog.example.com<br>若不限制跨域域名，请使用通配符 *。'));
        $form->addInput($origin);

        /* custom field privacy */
        $fieldsPrivacy = new Typecho_Widget_Helper_Form_Element_Text('fieldsPrivacy', null, null, _t('自定义字段过滤'), _t('过滤掉不希望在获取文章信息时显示的自定义字段名称。使用半角英文逗号分隔，例如 fields1,fields2 .'));
        $form->addInput($fieldsPrivacy);

        /* allowed options attribute */
        $allowedOptions = new Typecho_Widget_Helper_Form_Element_Text('allowedOptions', null, null, _t('自定义设置项白名单'), _t('默认情况下 /api/settings 只会返回一些安全的站点设置信息。若有需要你可以在这里指定允许返回的存在于 typecho_options 表中的字段，并通过 ?key= 参数请求。使用半角英文逗号分隔每个 key, 例如 keywords,theme .'));
        $form->addInput($allowedOptions);

        /* CSRF token salt */
        $csrfSalt = new Typecho_Widget_Helper_Form_Element_Text('csrfSalt', null, '05faabd6637f7e30c797973a558d4372', _t('CSRF加密盐'), _t('请务必修改本参数，以防止跨站攻击。'));
        $form->addInput($csrfSalt);

        /* API token */
        $apiToken = new Typecho_Widget_Helper_Form_Element_Text('apiToken', null, '123456', _t('APITOKEN'), _t('api请求需要携带的token，设置为空就不校验。'));
        $form->addInput($apiToken);

        /* 高敏接口是否校验登录用户 */
        $validateLogin = new Typecho_Widget_Helper_Form_Element_Radio('validateLogin', array(
            0 => _t('否'),
            1 => _t('是'),
        ), 0, _t('高敏接口是否校验登录'), _t('开启后，高敏接口需要携带Cookie才能访问'));
        $form->addInput($validateLogin);

        /* 阅读/点赞去重 Cookie 有效天数 */
        $counterCookieDays = new Typecho_Widget_Helper_Form_Element_Text(
            'counterCookieDays',
            null,
            '365',
            _t('计数Cookie有效天数'),
            _t('用于浏览数和点赞数去重；同一用户在 Cookie 未过期时不会重复计数。')
        );
        $form->addInput($counterCookieDays);

        /* Redis 计数缓存 */
        $redisEnabled = new Typecho_Widget_Helper_Form_Element_Radio('redisEnabled', array(
            0 => _t('关闭'),
            1 => _t('开启'),
        ), 0, _t('Redis 计数缓存'), _t('开启后会将浏览/点赞计数写入 Redis，并与数据库同步。'));
        $form->addInput($redisEnabled);

        $redisHost = new Typecho_Widget_Helper_Form_Element_Text('redisHost', null, '127.0.0.1', _t('Redis Host'), _t('例如 127.0.0.1'));
        $form->addInput($redisHost);

        $redisPort = new Typecho_Widget_Helper_Form_Element_Text('redisPort', null, '6379', _t('Redis Port'), _t('默认 6379'));
        $form->addInput($redisPort);

        $redisPassword = new Typecho_Widget_Helper_Form_Element_Text('redisPassword', null, null, _t('Redis Password'), _t('无密码可留空'));
        $form->addInput($redisPassword);

        $redisDb = new Typecho_Widget_Helper_Form_Element_Text('redisDb', null, '0', _t('Redis DB'), _t('默认 0'));
        $form->addInput($redisDb);

        $redisPrefix = new Typecho_Widget_Helper_Form_Element_Text('redisPrefix', null, 'typecho:restful', _t('Redis Key 前缀'), _t('用于隔离不同站点数据'));
        $form->addInput($redisPrefix);

        echo '<h3>Mori 更新通知</h3>';

        $moriWebhookUrl = new Typecho_Widget_Helper_Form_Element_Text(
            'moriWebhookUrl',
            null,
            '',
            _t('Mori 更新地址'),
            _t('用于接收更新通知的 Webhook 地址，例如 https://mori.example.com/api/typecho-webhook 。留空则不发送。')
        );
        $form->addInput($moriWebhookUrl);

        $moriWebhookToken = new Typecho_Widget_Helper_Form_Element_Text(
            'moriWebhookToken',
            null,
            '',
            _t('Mori Webhook Token'),
            _t('可选。将以请求头 X-Mori-Webhook-Token 发送。')
        );
        $form->addInput($moriWebhookToken);

        $moriWebhookTimeout = new Typecho_Widget_Helper_Form_Element_Text(
            'moriWebhookTimeout',
            null,
            '3',
            _t('Mori 请求超时(秒)'),
            _t('建议 2~5 秒，避免阻塞后台操作。')
        );
        $form->addInput($moriWebhookTimeout);

        $moriWebhookEvents = new Typecho_Widget_Helper_Form_Element_Checkbox(
            'moriWebhookEvents',
            array(
                'post' => _t('文章发布/更新'),
                'comment' => _t('评论通过审核'),
                'settings' => _t('网站设置变更'),
                'meta' => _t('新增/更新分类标签'),
            ),
            array('post', 'comment', 'settings', 'meta'),
            _t('Mori 更新触发事件'),
            _t('勾选需要触发 Mori 更新的事件。')
        );
        $form->addInput($moriWebhookEvents);
        ?>
<script>
function restfulUpgrade(e) {
    var originalText = e.innerHTML;
    var waitingText = '<?php echo _t('请稍后...');?>';
    if (e.innerHTML === waitingText) {
        return;
    }
    e.innerHTML = waitingText;
    var x = new XMLHttpRequest();
    x.open('GET', '<?php echo rtrim(Helper::options()->index, '/') . $prefix . 'upgrade';?>', true);
    x.onload = function() {
        var data = JSON.parse(x.responseText);
        if (x.status >= 200 && x.status < 400) {
            if (data.status === 'success') {
                alert('<?php echo _t('更新成功，您可能需要禁用插件再启用。');?>');
            } else {
                alert(data.message);
            }
        } else {
            alert(data.message);
        }
    };
    x.onerror = function() {
        alert('<?php echo _t('网络异常，请稍后再试');?>');
    };
    x.send();
}
</script>
        <?php
    }

    /**
     * 个人用户的配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    /**
     * 构造评论真实IP
     *
     * @return array
     */
    public static function comment($comment, $post)
    {
        $request = Typecho_Request::getInstance();

        $customIp = self::resolveCommentIp($request);
        if ($customIp !== null) {
            $comment['ip'] = $customIp;
        }

        return $comment;
    }

    /**
     * @param Typecho_Request $request
     * @return string|null
     */
    private static function resolveCommentIp($request)
    {
        $candidates = array(
            $request->getServer('HTTP_X_TYPECHO_RESTFUL_IP'),
            $request->getServer('HTTP_X_FORWARDED_FOR'),
            $request->getServer('HTTP_X_REAL_IP'),
            $request->getServer('HTTP_CF_CONNECTING_IP'),
            $request->getServer('HTTP_TRUE_CLIENT_IP'),
            $request->getServer('REMOTE_ADDR'),
        );

        foreach ($candidates as $candidate) {
            $normalized = self::normalizeCommentIp($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param mixed $rawValue
     * @return string|null
     */
    private static function normalizeCommentIp($rawValue)
    {
        if (!is_string($rawValue)) {
            return null;
        }

        $value = trim($rawValue);
        if ($value === '') {
            return null;
        }

        if (strpos($value, ',') !== false) {
            $segments = explode(',', $value);
            $value = trim($segments[0]);
        }

        if (stripos($value, 'for=') === 0) {
            $value = trim(substr($value, 4));
        }

        $value = trim($value, "\"' ");
        if ($value === '' || strtolower($value) === 'unknown') {
            return null;
        }

        if (strpos($value, '[') === 0 && strpos($value, ']') !== false) {
            $value = substr($value, 1, strpos($value, ']') - 1);
        } elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $value, $matches)) {
            $value = $matches[1];
        }

        if ($value === '') {
            return null;
        }

        return substr($value, 0, 128);
    }

    /**
     * 文章发布/更新后通知 Mori
     *
     * @param array|object $contents
     * @param Widget_Contents_Post_Edit $edit
     * @return void
     */
    public static function handlePostPublish($contents, $edit)
    {
        $type = self::readValue($contents, 'type', 'post');
        if ($type !== 'post') {
            return;
        }

        self::notifyMori('post.publish', self::buildContentPayload($contents));
    }

    /**
     * 评论状态变更后通知 Mori
     *
     * @param array|object $comment
     * @param Widget_Comments_Edit $edit
     * @param string $status
     * @return void
     */
    public static function handleCommentMark($comment, $edit, $status)
    {
        if ($status !== 'approved') {
            return;
        }

        $payload = array(
            'coid' => self::readInt($comment, 'coid'),
            'cid' => self::readInt($comment, 'cid'),
            'status' => $status,
            'created' => self::readValue($comment, 'created'),
        );

        self::notifyMori('comment.approved', $payload);
    }

    /**
     * 检测后台设置/分类/标签变更
     *
     * @return void
     */
    public static function handleRequestEnd()
    {
        $request = Typecho_Request::getInstance();
        if (!$request) {
            return;
        }

        if (!$request->isPost()) {
            return;
        }

        $action = self::resolveActionFromRequest($request);
        if ($action === '') {
            return;
        }

        if (strpos($action, 'options-') === 0) {
            self::notifyMori('settings.update', array(
                'group' => substr($action, strlen('options-')),
                'action' => $action,
            ));
            return;
        }

        if ($action === 'metas-category-edit' || $action === 'metas-tag-edit') {
            $payload = array(
                'action' => $action,
                'do' => $request->get('do'),
                'type' => $request->get('type'),
                'name' => $request->get('name'),
                'slug' => $request->get('slug'),
                'mid' => $request->get('mid'),
            );
            self::notifyMori('meta.update', self::filterPayload($payload));
        }
    }

    /**
     * 触发 Mori 更新通知
     *
     * @param string $event
     * @param array $payload
     * @return void
     */
    public static function notifyMori($event, array $payload = array())
    {
        $options = Helper::options();
        $config = $options->plugin('Restful');

        $url = isset($config->moriWebhookUrl) ? trim($config->moriWebhookUrl) : '';
        if ($url === '') {
            return;
        }

        $group = self::getEventGroup($event);
        if (!self::isMoriEventEnabled($config, $group)) {
            return;
        }

        if (!function_exists('curl_init')) {
            return;
        }

        $timeout = isset($config->moriWebhookTimeout) ? (int)$config->moriWebhookTimeout : 3;
        if ($timeout <= 0) {
            $timeout = 3;
        }

        $body = array(
            'event' => $event,
            'timestamp' => time(),
            'source' => 'typecho-restful',
            'site' => array(
                'title' => $options->title,
                'url' => $options->index,
            ),
            'payload' => $payload,
        );

        $headers = array('Content-Type: application/json; charset=utf-8');
        if (isset($config->moriWebhookToken) && trim($config->moriWebhookToken) !== '') {
            $headers[] = 'X-Mori-Webhook-Token: ' . trim($config->moriWebhookToken);
        }

        $jsonFlags = 0;
        if (defined('JSON_UNESCAPED_UNICODE')) {
            $jsonFlags |= JSON_UNESCAPED_UNICODE;
        }
        $jsonBody = json_encode($body, $jsonFlags);
        if ($jsonBody === false) {
            return;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * @param Typecho_Config $config
     * @param string $group
     * @return bool
     */
    private static function isMoriEventEnabled($config, $group)
    {
        if ($group === '') {
            return true;
        }

        if (!isset($config->moriWebhookEvents)) {
            return true;
        }

        $events = self::normalizeMoriEvents($config->moriWebhookEvents);
        return in_array($group, $events, true);
    }

    /**
     * @param mixed $raw
     * @return array
     */
    private static function normalizeMoriEvents($raw)
    {
        if (is_array($raw)) {
            return array_values(array_filter($raw));
        }

        if (!is_string($raw)) {
            return array();
        }

        $raw = trim($raw);
        if ($raw === '') {
            return array();
        }

        $parts = preg_split('/[,\|]/', $raw);
        $parts = array_map('trim', $parts);
        return array_values(array_filter($parts));
    }

    /**
     * @param string $event
     * @return string
     */
    private static function getEventGroup($event)
    {
        if (!is_string($event)) {
            return '';
        }

        $event = trim($event);
        if ($event === '') {
            return '';
        }

        $parts = explode('.', $event, 2);
        return strtolower(trim($parts[0]));
    }

    /**
     * @param Typecho_Request $request
     * @return string
     */
    private static function resolveActionFromRequest($request)
    {
        $pathInfo = '';
        if (method_exists($request, 'getPathInfo')) {
            $pathInfo = $request->getPathInfo();
        }
        if ($pathInfo === '' && method_exists($request, 'getRequestUri')) {
            $pathInfo = $request->getRequestUri();
        }

        if (!is_string($pathInfo) || $pathInfo === '') {
            return '';
        }

        $pathInfo = parse_url($pathInfo, PHP_URL_PATH);
        if (!is_string($pathInfo) || $pathInfo === '') {
            return '';
        }

        $pathInfo = '/' . ltrim($pathInfo, '/');
        if (strpos($pathInfo, '/index.php/') === 0) {
            $pathInfo = substr($pathInfo, strlen('/index.php'));
        }

        $pathInfo = ltrim($pathInfo, '/');
        if (strpos($pathInfo, 'action/') !== 0) {
            return '';
        }

        return substr($pathInfo, strlen('action/'));
    }

    /**
     * @param array|object $data
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private static function readValue($data, $key, $default = null)
    {
        if (is_array($data) && array_key_exists($key, $data)) {
            return $data[$key];
        }

        if (is_object($data) && isset($data->$key)) {
            return $data->$key;
        }

        return $default;
    }

    /**
     * @param array|object $data
     * @param string $key
     * @return int|null
     */
    private static function readInt($data, $key)
    {
        $value = self::readValue($data, $key);
        if (is_numeric($value)) {
            return (int)$value;
        }
        return null;
    }

    /**
     * @param array|object $contents
     * @return array
     */
    private static function buildContentPayload($contents)
    {
        return self::filterPayload(array(
            'cid' => self::readInt($contents, 'cid'),
            'slug' => self::readValue($contents, 'slug'),
            'title' => self::readValue($contents, 'title'),
            'type' => self::readValue($contents, 'type'),
            'status' => self::readValue($contents, 'status'),
            'created' => self::readValue($contents, 'created'),
            'modified' => self::readValue($contents, 'modified'),
        ));
    }

    /**
     * @param array $payload
     * @return array
     */
    private static function filterPayload(array $payload)
    {
        foreach ($payload as $key => $value) {
            if ($value === null || $value === '') {
                unset($payload[$key]);
            }
        }
        return $payload;
    }
}

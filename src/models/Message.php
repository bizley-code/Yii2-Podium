<?php

namespace bizley\podium\models;

use bizley\podium\components\Helper;
use bizley\podium\db\ActiveRecord;
use bizley\podium\db\Query;
use bizley\podium\log\Log;
use bizley\podium\models\User;
use bizley\podium\Podium;
use Exception;
use Yii;
use yii\behaviors\TimestampBehavior;
use yii\helpers\Html;
use yii\helpers\HtmlPurifier;

/**
 * Message model
 *
 * @author Paweł Bizley Brzozowski <pawel@positive.codes>
 * @since 0.1
 * 
 * @property integer $id
 * @property integer $sender_id
 * @property string $topic
 * @property string $content
 * @property integer $replyto
 * @property integer $sender_status
 * @property integer $updated_at
 * @property integer $created_at
 */
class Message extends ActiveRecord
{
    /**
     * Statuses.
     */
    const STATUS_NEW     = 1;
    const STATUS_READ    = 10;
    const STATUS_DELETED = 20;
    
    /**
     * Limits.
     */
    const MAX_RECEIVERS = 10;
    const SPAM_MESSAGES = 10;
    const SPAM_WAIT     = 1;
    
    /**
     * @var int[] Receivers' IDs.
     */
    public $receiversId;
    
    /**
     * @var int[] Friends' IDs.
     * @since 0.2
     */
    public $friendsId;
    
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%podium_message}}';
    }

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [TimestampBehavior::className()];
    }

    /**
     * @inheritdoc
     */
    public function scenarios()
    {
        return array_merge(
            parent::scenarios(),
            [
                'report' => ['content'],
                'remove' => ['sender_status'],
            ]                
        );
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['topic', 'content'], 'required'],
            [['receiversId', 'friendsId'], 'each', 'rule' => ['integer', 'min' => 1]],
            ['sender_status', 'in', 'range' => self::getStatuses()],
            ['topic', 'string', 'max' => 255],
            ['topic', 'filter', 'filter' => function($value) {
                return HtmlPurifier::process($value);
            }],
            ['content', 'filter', 'filter' => function($value) {
                return HtmlPurifier::process($value, Helper::podiumPurifierConfig());
            }],
        ];
    }
    
    /**
     * Returns Re: prefix for subject.
     * @return string
     */
    public static function re()
    {
        return Yii::t('podium/view', 'Re:');
    }

    /**
     * Returns list of statuses.
     * @return string[]
     */
    public static function getStatuses()
    {
        return [self::STATUS_NEW, self::STATUS_READ, self::STATUS_DELETED];
    }
    
    /**
     * Returns list of inbox statuses.
     * @return string[]
     */
    public static function getInboxStatuses()
    {
        return [self::STATUS_NEW, self::STATUS_READ];
    }
    
    /**
     * Returns list of sent statuses.
     * @return string[]
     */
    public static function getSentStatuses()
    {
        return [self::STATUS_READ];
    }
    
    /**
     * Returns list of deleted statuses.
     * @return string[]
     */
    public static function getDeletedStatuses()
    {
        return [self::STATUS_DELETED];
    }

    /**
     * Sender relation.
     * @return User
     */
    public function getSender()
    {
        return $this->hasOne(User::className(), ['id' => 'sender_id']);
    }
    
    /**
     * Receivers relation.
     * @return MessageReceiver[]
     */
    public function getMessageReceivers()
    {
        return $this->hasMany(MessageReceiver::className(), ['message_id' => 'id']);
    }
    
    /**
     * Checks if user is a message receiver.
     * @param int $user_id
     * @return bool
     */
    public function isMessageReceiver($user_id)
    {
        if ($this->messageReceivers) {
            foreach ($this->messageReceivers as $receiver) {
                if ($receiver->receiver_id == $user_id) {
                    return true;
                }
            }
        }
        return false;
    }
    
    /**
     * Returns reply Message.
     * @return Message
     */
    public function getReply()
    {
        return $this->hasOne(static::className(), ['id' => 'replyto']);
    }
    
    /**
     * Sends message.
     * @return bool
     */
    public function send()
    {
        $transaction = static::getDb()->beginTransaction();
        try {
            $this->sender_id = User::loggedId();
            $this->sender_status = self::STATUS_READ;
            
            if (!$this->save()) {
                throw new Exception('Message saving error!');
            }
            
            $count = count($this->receiversId);
            foreach ($this->receiversId as $receiver) {
                if (!(new Query)->select('id')->from(User::tableName())->where(['id' => $receiver, 'status' => User::STATUS_ACTIVE])->exists()) {
                    if ($count == 1) {
                        throw new Exception('No active receivers to send message to!');
                    }
                    continue;
                }
                $message = new MessageReceiver;
                $message->message_id = $this->id;
                $message->receiver_id = $receiver;
                $message->receiver_status = self::STATUS_NEW;
                if (!$message->save()) {
                    throw new Exception('MessageReceiver saving error!');
                }
                Podium::getInstance()->cache->deleteElement('user.newmessages', $receiver);
            }
            $transaction->commit();
            $sessionKey = 'messages.' . $this->sender_id;
            if (Yii::$app->session->has($sessionKey)) {
                $sentAlready = explode('|', Yii::$app->session->get($sessionKey));
                $sentAlready[] = time();
                Yii::$app->session->set($sessionKey, implode('|', $sentAlready));
            } else {
                Yii::$app->session->set($sessionKey, time());
            }
            return true;
        } catch (Exception $e) {
            $transaction->rollBack();
            Log::error($e->getMessage(), $this->id, __METHOD__);
        }
        return false;
    }
    
    /**
     * Checks if user sent already more than SPAM_MESSAGES in last SPAM_WAIT 
     * minutes.
     * @param int $user_id
     * @return bool
     */
    public static function tooMany($user_id)
    {
        $sessionKey = 'messages.' . $user_id;
        if (Yii::$app->session->has($sessionKey)) {
            $sentAlready = explode('|', Yii::$app->session->get($sessionKey));
            $validated = [];
            foreach ($sentAlready as $t) {
                if (preg_match('/^[0-9]+$/', $t)) {
                    if ($t > time() - self::SPAM_WAIT * 60) {
                        $validated[] = $t;
                    }
                }
            }
            Yii::$app->session->set($sessionKey, implode('|', $validated));
            if (count($validated) >= self::SPAM_MESSAGES) {
                return true;
            }
        }
        return false;
    }

    /**
     * Removes message.
     * @return bool
     * @throws Exception
     */
    public function remove()
    {
        $transaction = static::getDb()->beginTransaction();
        try {
            $clearCache = false;
            if ($this->sender_status == self::STATUS_NEW) {
                $clearCache = true;
            }
            $this->scenario = 'remove';
            if (empty($this->messageReceivers)) {
                if (!$this->delete()) {
                    throw new Exception('Message removing error!');
                }
                if ($clearCache) {
                    Podium::getInstance()->cache->deleteElement('user.newmessages', $this->sender_id);
                }
                $transaction->commit();
                return true;
            } else {
                $allDeleted = true;
                foreach ($this->messageReceivers as $mr) {
                    if ($mr->receiver_status != MessageReceiver::STATUS_DELETED) {
                        $allDeleted = false;
                        break;
                    }
                }
                if ($allDeleted) {
                    foreach ($this->messageReceivers as $mr) {
                        if (!$mr->delete()) {
                            throw new Exception('Received message removing error!');
                        }
                    }
                    if (!$this->delete()) {
                        throw new Exception('Message removing error!');
                    }
                    if ($clearCache) {
                        Podium::getInstance()->cache->deleteElement('user.newmessages', $this->sender_id);
                    }
                    $transaction->commit();
                    return true;
                } else {
                    $this->sender_status = self::STATUS_DELETED;
                    if (!$this->save()) {
                        throw new Exception('Message status changing error!');
                    }
                    if ($clearCache) {
                        Podium::getInstance()->cache->deleteElement('user.newmessages', $this->sender_id);
                    }
                    $transaction->commit();
                    return true;
                }
            }
        } catch (Exception $e) {
            $transaction->rollBack();
            Log::error($e->getMessage(), $this->id, __METHOD__);
        }
        return false;
    }
    
    /**
     * Performs post report sending to moderators.
     * @param Post $post reported post
     * @return bool
     * @since 0.2
     */
    public function podiumReport($post = null)
    {
        try {
            if (empty($post)) {
                throw new Exception('Reported post missing');
            }
            $logged = User::loggedId();
            $this->sender_id = $logged;
            $this->topic = Yii::t('podium/view', 'Complaint about the post #{id}', ['id' => $post->id]);
            $this->content .= '<hr>' 
                            . Html::a(Yii::t('podium/view', 'Direct link to this post'), ['forum/show', 'id' => $post->id]) 
                            . '<hr>' 
                            . '<p>' . Yii::t('podium/view', 'Post contents') . '</p>'
                            . '<blockquote>' . $post->content . '</blockquote>';
            $this->sender_status = self::STATUS_DELETED;
            if (!$this->save()) {
                throw new Exception('Saving complaint error!');
            }
            
            $receivers = [];
            $mods = $post->forum->mods;
            $stamp = time();
            foreach ($mods as $mod) {
                if ($mod != $logged) {
                    $receivers[] = [$this->id, $mod, self::STATUS_NEW, $stamp, $stamp];
                }
            }
            if (empty($receivers)) {
                throw new Exception('No one to send report to');
            }
            Podium::getInstance()->db->createCommand()->batchInsert(
                    MessageReceiver::tableName(), 
                    ['message_id', 'receiver_id', 'receiver_status', 'created_at', 'updated_at'], 
                    $receivers
                )->execute();

            Podium::getInstance()->cache->delete('user.newmessages');
            Log::info('Post reported', $post->id, __METHOD__);
            return true;
        } catch (Exception $e) {
            Log::error($e->getMessage(), null, __METHOD__);
        }
        return false;
    }
    
    /**
     * Marks message read.
     * @since 0.2
     */
    public function markRead()
    {
        if ($this->sender_status == self::STATUS_NEW) {
            $this->sender_status = self::STATUS_READ;
            if ($this->save()) {
                Podium::getInstance()->cache->deleteElement('user.newmessages', $this->sender_id);
            }
        }
    }
}

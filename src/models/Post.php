<?php

namespace bizley\podium\models;

use bizley\podium\components\Cache;
use bizley\podium\components\Helper;
use bizley\podium\db\ActiveRecord;
use bizley\podium\db\Query;
use bizley\podium\log\Log;
use bizley\podium\Podium;
use Exception;
use Yii;
use yii\behaviors\TimestampBehavior;
use yii\data\ActiveDataProvider;
use yii\helpers\Html;
use yii\helpers\HtmlPurifier;

/**
 * Post model
 *
 * @author Paweł Bizley Brzozowski <pawel@positive.codes>
 * @since 0.1
 * 
 * @property integer $id
 * @property string $content
 * @property integer $thread_id
 * @property integer $forum_id
 * @property integer $author_id
 * @property integer $likes
 * @property integer $dislikes
 * @property integer $updated_at
 * @property integer $created_at
 */
class Post extends ActiveRecord
{
    /**
     * @var bool Subscription flag.
     */
    public $subscribe;
    
    /**
     * @var string Topic.
     */
    public $topic;
    
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%podium_post}}';
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
    public function rules()
    {
        return [
            ['topic', 'required', 'message' => Yii::t('podium/view', 'Topic can not be blank.'), 'on' => ['firstPost']],
            ['topic', 'filter', 'filter' => function ($value) {
                return HtmlPurifier::process(Html::encode($value));
            }, 'on' => ['firstPost']],
            ['subscribe', 'boolean'],
            ['content', 'required'],
            ['content', 'filter', 'filter' => function ($value) {
                return HtmlPurifier::process($value, Helper::podiumPurifierConfig('full'));
            }],
            ['content', 'string', 'min' => 10],
        ];
    }

    /**
     * Author relation.
     * @return User
     */
    public function getAuthor()
    {
        return $this->hasOne(User::className(), ['id' => 'author_id']);
    }
    
    /**
     * Thread relation.
     * @return Thread
     */
    public function getThread()
    {
        return $this->hasOne(Thread::className(), ['id' => 'thread_id']);
    }
    
    /**
     * Forum relation.
     * @return Forum
     */
    public function getForum()
    {
        return $this->hasOne(Forum::className(), ['id' => 'forum_id']);
    }
    
    /**
     * Thumbs relation.
     * @return PostThumb
     */
    public function getThumb()
    {
        return $this->hasOne(PostThumb::className(), ['post_id' => 'id'])->where(['user_id' => User::loggedId()]);
    }
    
    /**
     * Returns latest posts for registered users.
     * @param int $limit Number of latest posts.
     * @return Post[]
     */
    public static function getLatestPostsForMembers($limit = 5)
    {
        return static::find()->orderBy(['created_at' => SORT_DESC])->limit($limit)->all();
    }
    
    /**
     * Returns latest visible posts for guests.
     * @param int $limit Number of latest posts.
     * @return Post[]
     */
    public static function getLatestPostsForGuests($limit = 5)
    {
        return static::find()->joinWith(['forum' => function ($query) {
            $query->andWhere([Forum::tableName() . '.visible' => 1])->joinWith(['category' => function ($query) {
                $query->andWhere([Category::tableName() . '.visible' => 1]);
            }]);
        }])->orderBy(['created_at' => SORT_DESC])->limit($limit)->all();
    }
    
    /**
     * Updates post tag words.
     * @param bool $insert
     * @param array $changedAttributes
     * @throws Exception
     */
    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);
        try {
            if ($insert) {
                $this->insertWords();
            } else {
                $this->updateWords();
            }
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Prepares tag words.
     * @return string[]
     */
    protected function prepareWords()
    {
        $cleanHtml = HtmlPurifier::process($this->content);
        $purged = preg_replace('/<[^>]+>/', ' ', $cleanHtml);
        $wordsRaw = array_unique(preg_split('/[\s,\.\n]+/', $purged));
        $allWords = [];
        foreach ($wordsRaw as $word) {
            if (mb_strlen($word, 'UTF-8') > 2 && mb_strlen($word, 'UTF-8') <= 255) {
                $allWords[] = $word;
            }
        }
        return $allWords;
    }
    
    /**
     * Adds new tag words.
     * @param string[] $allWords All words extracted from post
     * @throws Exception
     */
    protected function addNewWords($allWords)
    {
        try {
            $newWords = $allWords;
            $query = (new Query)->from(Vocabulary::tableName())->where(['word' => $allWords]);
            foreach ($query->each() as $vocabularyFound) {
                if (($key = array_search($vocabularyFound['word'], $allWords)) !== false) {
                    unset($newWords[$key]);
                }
            }
            $formatWords = [];
            foreach ($newWords as $word) {
                $formatWords[] = [$word];
            }
            if (!empty($formatWords)) {
                Podium::getInstance()->db->createCommand()->batchInsert(Vocabulary::tableName(), ['word'], $formatWords)->execute();
            }
        } catch (Exception $e) {
            Log::error($e->getMessage(), null, __METHOD__);
            throw $e;
        }
    }
    
    /**
     * Inserts tag words.
     * @throws Exception
     */
    protected function insertWords()
    {
        try {
            $vocabulary = [];
            $allWords = $this->prepareWords();
            $this->addNewWords($allWords);
            $query = (new Query)->from(Vocabulary::tableName())->where(['word' => $allWords]);
            foreach ($query->each() as $vocabularyNew) {
                $vocabulary[] = [$vocabularyNew['id'], $this->id];
            }
            if (!empty($vocabulary)) {
                Podium::getInstance()->db->createCommand()->batchInsert('{{%podium_vocabulary_junction}}', ['word_id', 'post_id'], $vocabulary)->execute();
            }
        } catch (Exception $e) {
            Log::error($e->getMessage(), null, __METHOD__);
            throw $e;
        }
    }

    /**
     * Updates tag words.
     * @throws Exception
     */
    protected function updateWords()
    {
        try {
            $vocabulary = [];
            $allWords = $this->prepareWords();
            $this->addNewWords($allWords);
            $query = (new Query)->from(Vocabulary::tableName())->where(['word' => $allWords]);
            foreach ($query->each() as $vocabularyNew) {
                $vocabulary[$vocabularyNew['id']] = [$vocabularyNew['id'], $this->id];
            }
            if (!empty($vocabulary)) {
                Podium::getInstance()->db->createCommand()->batchInsert('{{%podium_vocabulary_junction}}', ['word_id', 'post_id'], array_values($vocabulary))->execute();
            }
            $query = (new Query)->from('{{%podium_vocabulary_junction}}')->where(['post_id' => $this->id]);
            foreach ($query->each() as $junk) {
                if (!array_key_exists($junk['word_id'], $vocabulary)) {
                    Podium::getInstance()->db->createCommand()->delete('{{%podium_vocabulary_junction}}', ['id' => $junk['id']])->execute();
                }
            }
        } catch (Exception $e) {
            Log::error($e->getMessage(), null, __METHOD__);
            throw $e;
        }
    }
    
    /**
     * Verifies if user is this forum's moderator.
     * @param int|null $user_id User's ID or null for current signed in.
     * @return bool
     */
    public function isMod($user_id = null)
    {
        return $this->forum->isMod($user_id);
    }
    
    /**
     * Searches for posts.
     * @param int $forum_id
     * @param int $thread_id
     * @return ActiveDataProvider
     */
    public function search($forum_id, $thread_id)
    {
        $dataProvider = new ActiveDataProvider([
            'query' => static::find()->where(['forum_id' => $forum_id, 'thread_id' => $thread_id]),
            'pagination' => [
                'defaultPageSize' => 10,
                'pageSizeLimit' => false,
                'forcePageParam' => false
            ],
        ]);
        $dataProvider->sort->defaultOrder = ['id' => SORT_ASC];
        return $dataProvider;
    }
    
    /**
     * Searches for posts added by given user.
     * @param int $user_id
     * @return ActiveDataProvider
     */
    public function searchByUser($user_id)
    {
        $query = static::find();
        $query->where(['author_id' => $user_id]);
        if (Podium::getInstance()->user->isGuest) {
            $query->joinWith(['forum' => function($q) {
                $q->where([Forum::tableName() . '.visible' => 1]);
            }]);
        }

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'pagination' => [
                'defaultPageSize' => 10,
                'pageSizeLimit' => false,
                'forcePageParam' => false
            ],
        ]);
        $dataProvider->sort->defaultOrder = ['id' => SORT_ASC];

        return $dataProvider;
    }
    
    /**
     * Marks post as seen by current user.
     * @param bool $updateCounters Whether to update view counter
     */
    public function markSeen($updateCounters = true)
    {
        if (!Podium::getInstance()->user->isGuest) {
            $threadView = ThreadView::find()->where(['user_id' => User::loggedId(), 'thread_id' => $this->thread_id])->limit(1)->one();
            if (empty($threadView)) {
                $threadView = new ThreadView;
                $threadView->user_id = User::loggedId();
                $threadView->thread_id = $this->thread_id;
                $threadView->new_last_seen = $this->created_at;
                $threadView->edited_last_seen = !empty($this->edited_at) ? $this->edited_at : $this->created_at;
                $threadView->save();
                if ($updateCounters) {
                    $this->thread->updateCounters(['views' => 1]);
                }
            } else {
                if ($this->edited) {
                    if ($threadView->edited_last_seen < $this->edited_at) {
                        $threadView->edited_last_seen = $this->edited_at;
                        $threadView->save();
                        if ($updateCounters) {
                            $this->thread->updateCounters(['views' => 1]);
                        }
                    }
                } else {
                    $save = false;
                    if ($threadView->new_last_seen < $this->created_at) {
                        $threadView->new_last_seen = $this->created_at;
                        $save = true;
                    }
                    if ($threadView->edited_last_seen < max($this->created_at, $this->edited_at)) {
                        $threadView->edited_last_seen = max($this->created_at, $this->edited_at);
                        $save = true;
                    }
                    if ($save) {
                        $threadView->save();
                        if ($updateCounters) {
                            $this->thread->updateCounters(['views' => 1]);
                        }
                    }
                }
            }
            if ($this->thread->subscription) {
                if ($this->thread->subscription->post_seen == Subscription::POST_NEW) {
                    $this->thread->subscription->post_seen = Subscription::POST_SEEN;
                    $this->thread->subscription->save();
                }
            }
        }
    }
    
    /**
     * Returns latest post.
     * @return type
     */
    public static function getLatest($posts = 5)
    {
        $latest = [];
        
        if (Podium::getInstance()->user->isGuest) {
            $latest = Podium::getInstance()->cache->getElement('forum.latestposts', 'guest');
            if ($latest === false) {
                $posts = static::getLatestPostsForGuests($posts);
                foreach ($posts as $post) {
                    $latest[] = [
                        'id' => $post->id,
                        'title' => $post->thread->name,
                        'created' => $post->created_at,
                        'author' => $post->author->podiumTag
                    ];
                }
                Podium::getInstance()->cache->setElement('forum.latestposts', 'guest', $latest);
            }
        } else {
            $latest = Podium::getInstance()->cache->getElement('forum.latestposts', 'member');
            if ($latest === false) {
                $posts = static::getLatestPostsForMembers($posts);
                foreach ($posts as $post) {
                    $latest[] = [
                        'id' => $post->id,
                        'title' => $post->thread->name,
                        'created' => $post->created_at,
                        'author' => $post->author->podiumTag
                    ];
                }
                Podium::getInstance()->cache->setElement('forum.latestposts', 'member', $latest);
            }
        }
        
        return $latest;
    }
    
    /**
     * Returns the verified post.
     * @param int $category_id post's category ID
     * @param int $forum_id post's forum ID
     * @param int $thread_id post's thread ID
     * @param int $id post's ID
     * @return Post
     * @since 0.2
     */
    public static function verify($category_id = null, $forum_id = null, $thread_id = null, $id = null)
    {
        if (!is_numeric($category_id) 
                || $category_id < 1 
                || !is_numeric($forum_id) 
                || $forum_id < 1 
                || !is_numeric($thread_id) 
                || $thread_id < 1 
                || !is_numeric($id) 
                || $id < 1) {
            return null;
        }
        return static::find()
                ->joinWith([
                    'thread', 
                    'forum' => function ($query) use ($category_id) {
                        $query->joinWith(['category'])->andWhere([Category::tableName() . '.id' => $category_id]);
                    }
                ])
                ->where([
                    static::tableName() . '.id' => $id, 
                    static::tableName() . '.thread_id' => $thread_id,
                    static::tableName() . '.forum_id' => $forum_id,
                ])
                ->limit(1)
                ->one();
    }
    
    /**
     * Performs post delete with parent forum and thread counters update.
     * @return bool
     * @since 0.2
     */
    public function podiumDelete()
    {
        $transaction = static::getDb()->beginTransaction();
        try {
            if ($this->delete()) {
                $wholeThread = false;
                if ($this->thread->postsCount) {
                    $this->thread->updateCounters(['posts' => -1]);
                    $this->forum->updateCounters(['posts' => -1]);
                } else {
                    $wholeThread = true;
                    $this->thread->delete();
                    $this->forum->updateCounters(['posts' => -1, 'threads' => -1]);
                }
                $transaction->commit();
                Cache::clearAfter('postDelete');
                Log::info('Post deleted', !empty($this->id) ? $this->id : '', __METHOD__);
                return true;
            }
        } catch (Exception $e) {
            $transaction->rollBack();
            Log::error($e->getMessage(), null, __METHOD__);
        }
        return false;
    }
    
    /**
     * Performs post update with parent thread topic update in case of first post in thread.
     * @param bool $isFirstPost whether post is first in thread
     * @return bool
     * @since 0.2
     */
    public function podiumEdit($isFirstPost = false)
    {
        $transaction = static::getDb()->beginTransaction();
        try {
            $this->edited = 1;
            $this->touch('edited_at');

            if ($this->save()) {
                if ($isFirstPost) {
                    $this->thread->name = $this->topic;
                    $this->thread->save();
                }
                $this->markSeen();
                $this->thread->touch('edited_post_at');
            }
            $transaction->commit();
            Log::info('Post updated', $this->id, __METHOD__);
            return true;
        } catch (Exception $e) {
            $transaction->rollBack();
            Log::error($e->getMessage(), null, __METHOD__);
        }
        return false;
    }
    
    /**
     * Performs new post creation and subscription.
     * If previous post in thread has got the same author and merge_posts is set 
     * posts are merged.
     * @param Post $previous previous post
     * @return bool
     * @throws Exception
     * @since 0.2
     */
    public function podiumNew($previous = null)
    {
        $transaction = static::getDb()->beginTransaction();
        try {
            $id = null;
            $sameAuthor = !empty($previous->author_id) && $previous->author_id == User::loggedId();
            if ($sameAuthor && Podium::getInstance()->config->get('merge_posts')) {
                $previous->content .= '<hr>' . $this->content;
                $previous->edited = 1;
                $previous->touch('edited_at');
                if ($previous->save()) {
                    $previous->markSeen(false);
                    $previous->thread->touch('edited_post_at');
                    $id = $previous->id;
                    $thread = $previous->thread;
                }
            } else {
                if ($this->save()) {
                    $this->markSeen(!$sameAuthor);
                    $this->forum->updateCounters(['posts' => 1]);
                    $this->thread->updateCounters(['posts' => 1]);
                    $this->thread->touch('new_post_at');
                    $this->thread->touch('edited_post_at');
                    $id = $this->id;
                    $thread = $this->thread;
                }
            }
            if (empty($id)) {
                throw new Exception('Saved Post ID missing');
            }
            Subscription::notify($thread->id);
            if ($this->subscribe && !$thread->subscription) {
                $subscription = new Subscription();
                $subscription->user_id = User::loggedId();
                $subscription->thread_id = $thread->id;
                $subscription->post_seen = Subscription::POST_SEEN;
                $subscription->save();
            }
            $transaction->commit();
            Cache::clearAfter('newPost');
            Log::info('Post added', $id, __METHOD__);
            return true;
        } catch (Exception $e) {
            $transaction->rollBack();
            Log::error($e->getMessage(), null, __METHOD__);
        }
        return false;
    }
    
    /**
     * Performs vote processing.
     * @param bool $up whether this is up or downvote
     * @param int $count number of user's cached votes
     * @return bool
     * @since 0.2
     */
    public function podiumThumb($up = true, $count = 0)
    {
        try {
            if ($this->thumb) {
                if ($this->thumb->thumb == 1 && !$up) {
                    $this->thumb->thumb = -1;
                    if ($this->thumb->save()) {
                        $this->updateCounters(['likes' => -1, 'dislikes' => 1]);
                    }
                } elseif ($this->thumb->thumb == -1 && $up) {
                    $this->thumb->thumb = 1;
                    if ($this->thumb->save()) {
                        $this->updateCounters(['likes' => 1, 'dislikes' => -1]);
                    }
                }
            } else {
                $postThumb = new PostThumb;
                $postThumb->post_id = $this->id;
                $postThumb->user_id = User::loggedId();
                $postThumb->thumb = $up ? 1 : -1;
                if ($postThumb->save()) {
                    if ($postThumb->thumb) {
                        $this->updateCounters(['likes' => 1]);
                    } else {
                        $this->updateCounters(['dislikes' => 1]);
                    }
                }
            }
            if ($count == 0) {
                Podium::getInstance()->cache->set('user.votes.' . User::loggedId(), ['count' => 1, 'expire' => time() + 3600]);
            } else {
                Podium::getInstance()->cache->setElement('user.votes.' . User::loggedId(), 'count', $count + 1);
            }
            return true;
        } catch (Exception $e) {
            Log::error($e->getMessage(), null, __METHOD__);
        }
        return false;
    }
}

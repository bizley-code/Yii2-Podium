<?php

namespace bizley\podium\controllers;

use bizley\podium\db\Query;
use bizley\podium\models\Category;
use bizley\podium\models\Forum;
use bizley\podium\models\Post;
use bizley\podium\models\Thread;
use bizley\podium\Podium;
use Exception;
use Yii;
use yii\filters\AccessControl;
use yii\web\Response;

/**
 * Podium Forum controller
 * All actions concerning viewing and moderating forums and posts.
 * 
 * @author Paweł Bizley Brzozowski <pawel@positive.codes>
 * @since 0.2
 */
class ForumController extends BaseForumActionsController
{
    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'user' => $this->module->user,
                'rules' => [
                    [
                        'allow' => false,
                        'matchCallback' => function ($rule, $action) {
                            return !$this->module->getInstalled();
                        },
                        'denyCallback' => function ($rule, $action) {
                            return $this->redirect(['install/run']);
                        }
                    ],
                    ['allow' => true],
                ],
            ],
        ];
    }

    /**
     * Showing ban info.
     * @return string
     */
    public function actionBan()
    {
        $this->layout = 'maintenance';
        return $this->render('ban');
    }
    
    /**
     * Displaying the category of given ID and slug.
     * @param int $id
     * @param string $slug
     * @return string|Response
     */
    public function actionCategory($id = null, $slug = null)
    {
        if (!is_numeric($id) || $id < 1 || empty($slug)) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the category you are looking for.'));
            return $this->redirect(['forum/index']);
        }

        $conditions = ['id' => (int)$id, 'slug' => $slug];
        if (Podium::getInstance()->user->isGuest) {
            $conditions['visible'] = 1;
        }
        $model = Category::find()->where($conditions)->limit(1)->one();
        if (!$model) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the category you are looking for.'));
            return $this->redirect(['forum/index']);
        }
        
        $this->setMetaTags($model->keywords, $model->description);

        return $this->render('category', ['model' => $model]);
    }
    
    /**
     * Displaying the forum of given category ID, own ID and slug.
     * @param int $cid category ID
     * @param int $id forum ID
     * @param string $slug forum slug
     * @return string|Response
     */
    public function actionForum($cid = null, $id = null, $slug = null, $toggle = null)
    {
        $filters = Yii::$app->session->get('forum-filters');
        if (in_array(strtolower($toggle), ['new', 'edit', 'hot', 'pin', 'lock', 'all'])) {
            if (strtolower($toggle) == 'all') {
                $filters = null;
            } else {
                $filters[strtolower($toggle)] = empty($filters[strtolower($toggle)]) || $filters[strtolower($toggle)] == 0 ? 1 : 0;
            }
            Yii::$app->session->set('forum-filters', $filters);
            return $this->redirect([
                'forum/forum', 
                'cid'  => $cid, 
                'id'   => $id, 
                'slug' => $slug
            ]);
        }
        
        $forum = Forum::verify($cid, $id, $slug, Podium::getInstance()->user->isGuest);
        if (empty($forum)) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the forum you are looking for.'));
            return $this->redirect(['forum/index']);
        }

        $this->setMetaTags(
            $forum->keywords ?: $forum->category->keywords, 
            $forum->description ?: $forum->category->description
        );
        
        return $this->render('forum', [
            'model'    => $forum,
            'filters'  => $filters
        ]);
    }
    
    /**
     * Displaying the list of categories.
     * @return string
     */
    public function actionIndex()
    {
        $this->setMetaTags();
        return $this->render('index', ['dataProvider' => (new Category)->search()]);
    }
    
    /**
     * Direct link for the last post in thread of given ID.
     * @param int $id
     * @return Response
     */
    public function actionLast($id = null)
    {
        if (!is_numeric($id) || $id < 1) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the thread you are looking for.'));
            return $this->redirect(['forum/index']);
        }
        
        $thread = Thread::find()->where(['id' => $id])->limit(1)->one();
        if (empty($thread)) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the thread you are looking for.'));
            return $this->redirect(['forum/index']);
        }
        
        $url = ['forum/thread', 
            'cid'  => $thread->category_id,
            'fid'  => $thread->forum_id, 
            'id'   => $thread->id, 
            'slug' => $thread->slug
        ];

        $count = $thread->postsCount;
        $page = floor($count / 10) + 1;
        if ($page > 1) {
            $url['page'] = $page;
        }
        return $this->redirect($url);
    }
    
    /**
     * Showing maintenance info.
     */
    public function actionMaintenance()
    {
        $this->layout = 'maintenance';
        return $this->render('maintenance');
    }
    
    /**
     * Direct link for the post of given ID.
     * @param int $id
     * @return Response
     */
    public function actionShow($id = null)
    {
        if (!is_numeric($id) || $id < 1) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the post you are looking for.'));
            return $this->redirect(['forum/index']);
        }
        
        $post = Post::find()->where(['id' => $id])->limit(1)->one();
        if (empty($post)) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the post you are looking for.'));
            return $this->redirect(['forum/index']);
        }
        
        $url = ['forum/thread', 
            'cid'  => $post->thread->category_id,
            'fid'  => $post->forum_id, 
            'id'   => $post->thread_id, 
            'slug' => $post->thread->slug
        ];

        try {
            $count = (new Query)
                        ->from(Post::tableName())
                        ->where([
                            'and', 
                            ['thread_id' => $post->thread_id], 
                            ['<', 'id', $post->id]
                        ])
                        ->count();
            $page = floor($count / 10) + 1;
            if ($page > 1) {
                $url['page'] = $page;
            }
            $url['#'] = 'post' . $post->id;
            return $this->redirect($url);
        } catch (Exception $e) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the post you are looking for.'));
            return $this->redirect(['forum/index']);
        }
    }

    /**
     * Displaying the thread of given category ID, forum ID, own ID and slug.
     * @param int $cid category ID
     * @param int $fid forum ID
     * @param int $id thread ID
     * @param string $slug thread slug
     * @return string|Response
     */
    public function actionThread($cid = null, $fid = null, $id = null, $slug = null)
    {
        $thread = Thread::verify($cid, $fid, $id, $slug, Podium::getInstance()->user->isGuest);
        if (empty($thread)) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find the thread you are looking for.'));
            return $this->redirect(['forum/index']);
        }

        $this->setMetaTags(
            $thread->forum->keywords ?: $thread->forum->category->keywords, 
            $thread->forum->description ?: $thread->forum->category->description
        );
        
        $dataProvider = (new Post)->search($thread->forum->id, $thread->id);
        $model = new Post;
        $model->subscribe = 1;

        return $this->render('thread', [
            'model'        => $model,
            'dataProvider' => $dataProvider,
            'thread'       => $thread,
        ]);
    }

    /**
     * Setting meta tags.
     * @param string $keywords
     * @param string $description
     */
    public function setMetaTags($keywords = null, $description = null)
    {
        if (empty($keywords)) {
            $keywords = $this->module->config->get('meta_keywords');
        }
        if ($keywords) {
            $this->getView()->registerMetaTag([
                'name'    => 'keywords',
                'content' => $keywords
            ]);
        }
        
        if (empty($description)) {
            $description = $this->module->config->get('meta_description');
        }
        if ($description) {
            $this->getView()->registerMetaTag([
                'name'    => 'description',
                'content' => $description
            ]);
        }
    }
    
    /**
     * Listing all unread posts.
     * @return string|Response
     */
    public function actionUnreadPosts()
    {
        if (Podium::getInstance()->user->isGuest) {
            $this->info(Yii::t('podium/flash', 'This page is available for registered users only.'));
            return $this->redirect(['account/login']);
        }
        return $this->render('unread-posts');
    }
}

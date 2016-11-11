<?php

namespace bizley\podium\models;

use bizley\podium\db\ActiveRecord;

/**
 * Mod model
 * Forum moderators.
 * 
 * @author Paweł Bizley Brzozowski <pawel@positive.codes>
 * @since 0.1
 * 
 * @property integer $id
 * @property integer $user_id
 * @property integer $forum_id
 */
class Mod extends ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%podium_moderator}}';
    }

    /**
     * Forum relation.
     * @return Forum
     */
    public function getForum()
    {
        return $this->hasOne(Forum::className(), ['id' => 'forum_id']);
    }
}

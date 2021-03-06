<?php


namespace tracker\controllers;

use humhub\modules\content\models\Content;
use humhub\modules\space\models\Membership;
use humhub\modules\space\models\Space;
use humhub\modules\stream\actions\Stream;
use humhub\modules\user\models\User;
use tracker\enum\IssueStatusEnum;
use tracker\enum\IssueVisibilityEnum;
use tracker\models\Assignee;
use tracker\models\Issue;
use yii\db\Query;

/**
 * @author Evgeniy Tkachenko <et.coder@gmail.com>
 */
class DashboardStreamAction extends Stream
{
    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        $friendshipEnabled = \Yii::$app->getModule('friendship')->getIsEnabled();

        if ($this->user == null) {

            /**
             * For guests collect all contentcontainer_ids of "guest" public spaces / user profiles.
             * Generally show only public content
             */
            $publicSpacesSql = (new Query())
                ->select(['contentcontainer.id'])
                ->from('space')
                ->leftJoin('contentcontainer', 'space.id=contentcontainer.pk AND contentcontainer.class=:spaceClass')
                ->where('space.visibility=' . Space::VISIBILITY_ALL);
            $union = \Yii::$app->db->getQueryBuilder()->build($publicSpacesSql)[0];

            $publicProfilesSql = (new Query())
                ->select('contentcontainer.id')
                ->from('user')
                ->leftJoin('contentcontainer', 'user.id=contentcontainer.pk AND contentcontainer.class=:userClass')
                ->where('user.status=1 AND user.visibility = ' . User::VISIBILITY_ALL);
            $union .= ' UNION ' . \Yii::$app->db->getQueryBuilder()->build($publicProfilesSql)[0];

            $this->activeQuery->andWhere(
                'content.contentcontainer_id IN (' . $union .
                                         ') OR content.contentcontainer_id IS NULL',
                [':spaceClass' => Space::className(), ':userClass' => User::className()]
            );
            $this->activeQuery->andWhere(['content.visibility' => Content::VISIBILITY_PUBLIC]);
        } else {

            /**
             * Collect all wall_ids we need to include into dashboard stream
             */
            // Following (User to Space/User)
            $userFollows = (new Query())
                ->select(['contentcontainer.id'])
                ->from('user_follow')
                ->leftJoin(
                    'contentcontainer',
                    'contentcontainer.pk=user_follow.object_id AND contentcontainer.class=user_follow.object_model'
                )
                ->where('user_follow.user_id=' . $this->user->id .
                        ' AND (user_follow.object_model = :spaceClass OR user_follow.object_model = :userClass)');
            $union = \Yii::$app->db->getQueryBuilder()->build($userFollows)[0];

            // User to space memberships
            $spaceMemberships = (new Query())
                ->select('contentcontainer.id')
                ->from('space_membership')
                ->leftJoin('space sm', 'sm.id=space_membership.space_id')
                ->leftJoin('contentcontainer', 'contentcontainer.pk=sm.id AND contentcontainer.class = :spaceClass')
                ->where('space_membership.user_id=' . $this->user->id . ' AND space_membership.show_at_dashboard = 1');
            $union .= ' UNION ' . \Yii::$app->db->getQueryBuilder()->build($spaceMemberships)[0];

            if ($friendshipEnabled) {
                // User to user follows
                $usersFriends = (new Query())
                    ->select(['ufrc.id'])
                    ->from('user ufr')
                    ->leftJoin(
                        'user_friendship recv',
                        'ufr.id=recv.friend_user_id AND recv.user_id=' . (int)$this->user->id
                    )
                    ->leftJoin(
                        'user_friendship snd',
                        'ufr.id=snd.user_id AND snd.friend_user_id=' . (int)$this->user->id
                    )
                    ->leftJoin('contentcontainer ufrc', 'ufr.id=ufrc.pk AND ufrc.class=:userClass')
                    ->where('recv.id IS NOT NULL AND snd.id IS NOT NULL AND ufrc.id IS NOT NULL');
                $union .= ' UNION ' . \Yii::$app->db->getQueryBuilder()->build($usersFriends)[0];
            }

            // Glue together also with current users wall
            $wallIdsSql = (new Query())
                ->select('cc.id')
                ->from('contentcontainer cc')
                ->where('cc.pk=' . $this->user->id . ' AND cc.class=:userClass');
            $union .= ' UNION ' . \Yii::$app->db->getQueryBuilder()->build($wallIdsSql)[0];

            // Manual Union (https://github.com/yiisoft/yii2/issues/7992)
            $this->activeQuery->andWhere(
                'contentcontainer.id IN (' . $union . ') OR contentcontainer.id IS NULL',
                [':spaceClass' => Space::className(), ':userClass' => User::className()]
            );

            /**
             * Begin visibility checks regarding the content container
             */
            $this->activeQuery->leftJoin(
                'space_membership',
                'contentcontainer.pk=space_membership.space_id AND space_membership.user_id=:userId AND space_membership.status=:status',
                ['userId' => $this->user->id, ':status' => Membership::STATUS_MEMBER]
            );
            if ($friendshipEnabled) {
                $this->activeQuery->leftJoin(
                    'user_friendship',
                    'contentcontainer.pk=user_friendship.user_id AND user_friendship.friend_user_id=:userId',
                    ['userId' => $this->user->id]
                );
            }

            $condition = ' (contentcontainer.class=:userModel AND content.visibility=0 AND content.created_by = :userId)';
            if ($friendshipEnabled) {
                // In case of friendship we can also display private content
                $condition .= ' OR (contentcontainer.class=:userModel AND content.visibility=0 AND user_friendship.id IS NOT NULL)';
            }
            $this->activeQuery->orWhere($condition, [':userId' => $this->user->id, ':userModel' => User::className()]);
        }
    }

    public function setupCriteria()
    {
        if (empty($this->streamQuery->contentId)) {
            $tableIssue = Issue::tableName();
            $tableAssignee = Assignee::tableName();
            $tableContent = Content::tableName();
            $this->streamQuery
                ->query()
                ->leftJoin(
                    Issue::tableName(),
                    "object_id = $tableIssue.id AND content.object_model = :className",
                    [':className' => Issue::className()]
                )
                ->leftJoin(
                    Assignee::tableName(),
                    "$tableAssignee.issue_id = $tableIssue.id AND $tableAssignee.user_id = :user",
                    [':user' => \Yii::$app->user->id]
                )
                ->andWhere(
                    "$tableIssue.id IS NULL OR ($tableIssue.status != " . IssueStatusEnum::TYPE_DRAFT .
                    " AND ($tableContent.visibility != " . IssueVisibilityEnum::TYPE_PRIVATE .
                    " OR $tableAssignee.id IS NOT NULL OR $tableContent.created_by = :user))",
                    [':user' => \Yii::$app->user->id]
                );
        }
    }
}

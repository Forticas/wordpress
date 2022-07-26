<?php
/**
 * Created by PhpStorm.
 * User: turgutsaricam
 * Date: 08/04/16
 * Time: 09:52
 */

namespace WPCCrawler\Services;

use WPCCrawler\Objects\Settings\Factory\Cron\PostRecrawlCronKeyFactory;
use WP_Query;
use WPCCrawler\Environment;
use WPCCrawler\Factory;
use WPCCrawler\Objects\Settings\Enums\SettingKey;
use WPCCrawler\PostDetail\PostDetailsService;
use WPCCrawler\Objects\Crawling\Savers\PostSaver;
use WPCCrawler\Objects\Crawling\Savers\UrlSaver;
use WPCCrawler\Objects\Settings\SettingService;
use WPCCrawler\Objects\Traits\SettingsTrait;
use WPCCrawler\Utils;

class SchedulingService {

    // TODO: When saving via CRON, srcset attributes of img elements in the post content are not set. That's probably
    //  because there are no alternative-size images at that time. This might be the expected behavior or not.

    use SettingsTrait;

    /** @var bool */
    private static $DEBUG = false;

    /** @var array|null */
    private $intervals;

    /*
     * ATTENTION (if your newly-added CRON event does not work)
     *
     * So you added a new CRON event. You created its function, added its function as WordPress action, added its
     * settings, added "schedule" and "remove schedule" functions... Everything looks OK. But, you do not see your CRON
     * event among the scheduled events? How come? Right? Yeah. I wasted a lot of hours to fix that problem. That's
     * twice now. It's because of my interesting way of initializing the plugin.
     *
     * In short, go to wp-content-crawler.php and make sure SchedulingService is initialized when your new CRON event
     * is scheduled. You are gonna see a few "is active" checks before initializing SchedulingService. Just add your
     * new condition there.
     *
     * I forget that every time.
     */

    /** @var string */
    public $eventCollectUrls   = 'wpcc_event_collect_urls';
    /** @var string */
    public $eventCrawlPost     = 'wpcc_event_crawl_post';
    /** @var string */
    public $eventRecrawlPost   = 'wpcc_event_recrawl_post';
    /** @var string */
    public $eventDeletePosts   = 'wpcc_event_delete_posts';

    /** @var string Option key storing the ID of the site whose posts are deleted via delete event the last time. */
    public $optionKeyLastPostDeletedSiteId = '_last_post_deleted_site_id';

    /** @var int */
    private $maxRunCount = 1000;

    /**
     * @var array Stores the result of the db query used to retrieve active sites. It's a key-value pair. Keys are the
     *            meta keys that stores whether a site is active for an event or not. E.g. '_active',
     *            '_active_recrawling', etc.
     */
    private $activeSiteIdsCache = [];

    public function __construct() {
        // Add custom time intervals
        $this->setCRONIntervals();

        // Set what function to call for CRON events
        add_action($this->eventCollectUrls, [$this, 'executeEventCollectUrls']);
        add_action($this->eventCrawlPost,   [$this, 'executeEventCrawlPost']);
        add_action($this->eventRecrawlPost, [$this, 'executeEventRecrawlPost']);
        add_action($this->eventDeletePosts, [$this, 'executeEventDeletePosts']);

        // Activate/deactivate CRON jobs when the plugin is activated. No need to remove CRON jobs on deactivation/uninstall.
        // WordPress removes them if it cannot find the action for the events.
        register_activation_hook(Utils::getPluginFilePath(), function () {
            Factory::schedulingService()->handleCronEvents();
        });

        // Lower the maximum run count in the demo
        if (Environment::isDemo()) {
            $this->maxRunCount = 2;
        }
    }

    /*
     * COLLECT URLS
     */

    /**
     * Execute the event to collect URLs
     * @param bool $bypassInactiveScheduling true if you want to run this even if the scheduling is inactive
     */
    public function executeEventCollectUrls($bypassInactiveScheduling = false): void {
        if (!Factory::wptslmClient()->isUserCool()) return;

        // If the scheduling is not active, do not continue.
        if (!$bypassInactiveScheduling && !SettingService::isSchedulingActive()) {
            // Sometimes, when general settings are saved, the events cannot be unscheduled for some reason :) Here,
            // we're handling that case. If for some reason this method is called when the scheduling is inactive,
            // that means the CRON event could not be unscheduled. Let's just try again :)
            $this->removeURLCollectionAndCrawlingEvents();
            return;
        }

        $urlSaver = new UrlSaver();

        $this->execute($urlSaver->optionLastCheckedSiteId,
            // No site ID callback
            function() {
                Factory::urlSaver()->executeUrlSave(null);
            },

            // Get required run count callback
            function() {
                return $this->getSetting(SettingKey::WPCC_RUN_COUNT_URL_COLLECTION, 1);
            },

            // Execute event callback
            function($siteIdToCheck) use (&$urlSaver) {
                $urlSaver->executeUrlSave($siteIdToCheck);
            },

            // "Has event run" callback
            function() use (&$urlSaver) {
                return !$urlSaver->isRequestMade();
            }
        );
    }

    /*
     * CRAWL POST
     */

    /**
     * Execute the event to crawl a post.
     * @param bool $bypassInactiveScheduling true if you want to run this even if the scheduling is inactive
     */
    public function executeEventCrawlPost($bypassInactiveScheduling = false): void {
        if (!Factory::wptslmClient()->isUserCool()) return;

        // If the scheduling is not active, do not continue.
        if (!$bypassInactiveScheduling && !SettingService::isSchedulingActive()) {
            $this->removeURLCollectionAndCrawlingEvents();
            return;
        }

        $postSaver = new PostSaver();

        $this->execute($postSaver->optionLastCrawledSiteId, null,
            // Get required run count callback
            function() {
                return $this->getSetting(SettingKey::WPCC_RUN_COUNT_POST_CRAWL, 1);
            },

            // Execute event callback
            function($siteIdToCheck) use (&$postSaver) {
                $postSaver->executePostSave($siteIdToCheck);
            },

            // "Has event run" callback
            function() use (&$postSaver) {
                return !$postSaver->isRequestMade();
            }
        );

    }

    /*
     * RECRAWL POST
     */

    /**
     * Execute the event to recrawl a post.
     * @param bool $bypassInactiveScheduling true if you want to run this even if the scheduling is inactive
     */
    public function executeEventRecrawlPost($bypassInactiveScheduling = false): void {
        if (!Factory::wptslmClient()->isUserCool()) return;

        // If the scheduling is not active, do not continue.
        if (!$bypassInactiveScheduling && !SettingService::isRecrawlingActive()) {
            $this->removeRecrawlingEvent();
            return;
        }

        $postSaver = new PostSaver();
        $results = null;

        $this->execute($postSaver->optionLastRecrawledSiteId, null,
            // Get required run count callback
            function() {
                return $this->getSetting(SettingKey::WPCC_RUN_COUNT_POST_RECRAWL, 1);
            },

            // Execute event callback
            function($siteIdToCheck) use (&$postSaver, &$results) {
                global $wpdb;

                // Prepare "max recrawl count" part of the query
                $maxRecrawlCount = (int) $this->getSetting(SettingKey::WPCC_MAX_RECRAWL_COUNT, 0);
                $updateCountPart = $maxRecrawlCount > 0 ? " AND update_count < " . $maxRecrawlCount . " " : "";

                // Prepare "time between two recrawls" and "posts newer than" part
                $now = current_time('mysql');
                $timeBetweenRecrawlsInMin = (int) $this->getSetting(SettingKey::WPCC_MIN_TIME_BETWEEN_TWO_RECRAWLS_IN_MIN);
                $recrawledAtPart = $timeBetweenRecrawlsInMin ? " AND ((recrawled_at IS NULL AND saved_at < DATE_SUB('{$now}', INTERVAL {$timeBetweenRecrawlsInMin} MINUTE)) OR recrawled_at < DATE_SUB('{$now}', INTERVAL {$timeBetweenRecrawlsInMin} MINUTE)) " : "";

                $newerThanInMin = (int) $this->getSetting(SettingKey::WPCC_RECRAWL_POSTS_NEWER_THAN_IN_MIN);
                $newerThanPart = $newerThanInMin ? " AND saved_at > DATE_SUB('{$now}', INTERVAL {$newerThanInMin} MINUTE) " : "";

                // Get URL tuple to be recrawled
                // Make sure no post with 'trash' status can be selected. We're allowing 'draft' as well, in this case.
                $query = "SELECT t1.* FROM " . Factory::databaseService()->getDbTableUrlsName() . " t1
                    INNER JOIN {$wpdb->posts} t2 ON t1.saved_post_id = t2.ID
                    WHERE post_id = %d {$updateCountPart} {$recrawledAtPart} {$newerThanPart}
                        AND saved_post_id IS NOT NULL
                        AND is_locked = false
                        AND is_saved = true
                        AND t2.post_status <> 'trash'
                    ORDER BY recrawled_at ASC
                    LIMIT 1;";
                $results = $wpdb->get_results($wpdb->prepare($query, $siteIdToCheck));

                // If there is a URL tuple found, recrawl it.
                if(!empty($results)) {
                    $postSaver->executePostRecrawl($results[0]);

                } else {

                    // If there was a post waiting for recrawling to be finished, go on with it.
                    $lastCrawledUrlId   = $this->getSetting(PostRecrawlCronKeyFactory::getInstance()->getLastCrawledUrlIdKey());
                    $draftPostId        = $this->getSetting(PostRecrawlCronKeyFactory::getInstance()->getPostDraftIdKey());

                    // If the draft post does not exist, nullify the draft post meta for this site.
                    if($draftPostId && !get_post($draftPostId)) $draftPostId = null;

                    // If the draft post and its URL tuple exist, execute post recrawl on that URL tuple.
                    if($draftPostId && $urlTuple = Factory::databaseService()->getUrlById($lastCrawledUrlId)) {
                        $postSaver->executePostRecrawl($urlTuple);

                    // Otherwise, mark this site as last recrawled and nullify draft post meta for this site.
                    } else {
                        $postSaver->setIsRecrawl(true);
                        $postSaver->resetLastCrawled($siteIdToCheck);
                    }
                }
            },

            // "Has event run" callback
            function() use (&$results) {
                return empty($results);
            }
        );
    }

    /*
     * DELETE POSTS
     */

    /**
     * Execute event to delete posts.
     *
     * @param bool $bypassInactiveScheduling
     */
    public function executeEventDeletePosts($bypassInactiveScheduling = false): void {
        if (!Factory::wptslmClient()->isUserCool()) return;

        // If the scheduling is not active, do not continue.
        if (!$bypassInactiveScheduling && !SettingService::isDeletingActive()) {
            $this->removeDeletingEvent();
            return;
        }

        $optionKeyMaxPostsToDelete = SettingKey::WPCC_MAX_POST_COUNT_PER_POST_DELETE_EVENT;
        $maxPostsToDelete = (int) get_option($optionKeyMaxPostsToDelete);

        // If max posts value is not valid, use the default.
        if(!$maxPostsToDelete || $maxPostsToDelete < 1) {
            $maxPostsToDelete = Factory::generalSettingsController()->getDefaultGeneralSettings()[$optionKeyMaxPostsToDelete];
        }

        $protectedAttachmentIds = SettingService::getProtectedAttachmentIds();
        $remaining = $maxPostsToDelete;

        $this->execute($this->optionKeyLastPostDeletedSiteId, null,
            // "Get required run count" callback
            function() {
                return 1;
            },

            // "Execute event" callback
            function($siteIdToCheck) use (&$remaining, $protectedAttachmentIds) {
                global $wpdb;

                if($remaining < 1) return;

                // Get the time offset from the settings
                $optionKeyOlderThan = SettingKey::WPCC_DELETE_POSTS_OLDER_THAN_IN_MIN;

                $olderThanInMin = (int) $this->getSetting($optionKeyOlderThan);

                // If minute value is not valid, use the default.
                if(!$olderThanInMin || $olderThanInMin < 1) {
                    $olderThanInMin = Factory::generalSettingsController()->getDefaultGeneralSettings()[$optionKeyOlderThan];
                }

                $isDeleteAttachments = $this->getSetting(SettingKey::WPCC_IS_DELETE_POST_ATTACHMENTS);

                /*
                 *
                 */

                $postIds = Factory::databaseService()->getOldPostIdsForSite($siteIdToCheck, $olderThanInMin, $remaining);

                // TODO: Bulk delete posts or delete them WordPress way. Ask the user how the posts should be deleted.
                // Deleting the posts via WordPress way is inefficient.

                foreach($postIds as $id) {
                    // Make the registered detail factories delete what they are concerned with
                    PostDetailsService::getInstance()->delete($this->getSettingsImpl(), null);

                    if($isDeleteAttachments) {
                        // Delete the attachments
                        foreach(get_attached_media('image', $id) as $mediaPost) {
                            $attachmentId = $mediaPost->ID;
                            if (in_array($attachmentId, $protectedAttachmentIds)) {
                                continue;
                            }

                            wp_delete_post($attachmentId);
                        }
                    }

                    // First, set the post's URL deleted. This should be called before deleting the post. Because,
                    // when the post is deleted, its URL tuple's saved_post_id becomes null. If this happens, we cannot
                    // find the URL tuple belonging to this post. So, we need to delete its URL before it is deleted.
                    Factory::databaseService()->setUrlDeleted($id);

                    // Finally, delete the post
                    wp_delete_post($id, true);
                }

                // Set new remaining
                $remaining -= sizeof($postIds);

                // Update the last deleted time for the site
                Utils::savePostMeta($siteIdToCheck, SettingKey::CRON_LAST_DELETED_AT, current_time('mysql'), true);

                // Update the site ID option storing the last site ID whose posts are deleted
                update_option($this->optionKeyLastPostDeletedSiteId, $siteIdToCheck);
            },

            // "Has event run" callback
            function() use (&$remaining) {
                return $remaining > 0;
            }
        );

    }

    /**
     * Executes an event for a site as many times as it is required. If the event has not succeeded for a site, another
     * site will be tried for the event.
     *
     * @param string        $lastCheckedSiteIdOptionName  Option name that stores last checked site ID for this event.
     *                                                    E.g. '_wpcc_last_checked_site_id'
     * @param callable|null $noSiteIdCallback             A callback that will be called when there is no site ID to
     *                                                    interact with. No need to return anything.
     * @param callable      $getRequiredRunCountCallback  A callback that <b>must return an integer</b>. This integer
     *                                                    will be used to define how many times the event will run.
     * @param callable      $executeEventCallback         A callback that does the actual job this event should do.
     *                                                    This will be called multiple times depending on required run
     *                                                    count. <i>Takes params:</i> <b>(int) $siteIdToCheck</b>. No
     *                                                    need to return anything.
     * @param callable      $hasEventRunCallback          A callback that <b>must return a boolean</b>. If this returns
     *                                                    true for the first try, another site will be tried for this
     *                                                    event, if there are any more sites. If this returns true after
     *                                                    first try, later executions won't be run.
     */
    private function execute($lastCheckedSiteIdOptionName, $noSiteIdCallback, $getRequiredRunCountCallback, $executeEventCallback,
        $hasEventRunCallback): void {

        $siteIdToCheck = null;
        $run = false;

        /** @var int[] $triedSiteIds Stores IDs of the sites that are already tried. */
        $triedSiteIds = [];

        do {
            // If there is no site ID to check, get the next site ID that needs to be checked.
            if(!$siteIdToCheck) {
                // Get site ID to check
                $siteIdToCheck = $this->getSiteIdForEvent($lastCheckedSiteIdOptionName);

                // If there is no valid site ID to check, call the event so that it can handle the case where there is no
                // valid site ID.
                if(!$siteIdToCheck) {
                    if($noSiteIdCallback && is_callable($noSiteIdCallback)) call_user_func($noSiteIdCallback);
                    break;
                }
            }

            // If this site has already been tried, break the loop.
            if(array_search($siteIdToCheck, $triedSiteIds) !== false) break;

            // Get settings for the site ID
            $settings = get_post_meta($siteIdToCheck);
            $this->setSettings($settings, Factory::postService()->getSingleMetaKeys());

            // Get how many times this should run.
            $requiredRunCount = (int) call_user_func($getRequiredRunCountCallback);
            if($requiredRunCount < 1) $requiredRunCount = 1;

            // Run the event as many times as the user wants.
            $count = 0;
            do {
                // Invalidate all factory instances because we are getting posts from different sites using different
                // settings. We need fresh factories.
                PostDetailsService::getInstance()->invalidateFactoryInstances();

                // If this is not the first run, get the settings from the database and assign them to this class again.
                // This is important, because the settings might have been changed in the first run.
                if($count > 0) {
                    $settings = get_post_meta($siteIdToCheck);
                    $this->setSettings($settings, Factory::postService()->getSingleMetaKeys());
                }

                call_user_func($executeEventCallback, $siteIdToCheck);

                if($count === 0) {
                    // If this is the first run of this inner loop, add the site ID among tried site IDs.
                    $triedSiteIds[] = $siteIdToCheck;

                    // Try another site when required.
                    if(call_user_func($hasEventRunCallback)) {
//                        error_log("WPCC - Nothing to do. Try another site. Option: {$lastCheckedSiteIdOptionName}, Current Site ID: {$siteIdToCheck} ");
                        $run = true;
                        $siteIdToCheck = null;
                        break;

                    // Otherwise, make sure outer loop won't run again.
                    } else {
                        $run = false;
                    }

                // If the event was not executed after the first run, break the loops.
                } else if(call_user_func($hasEventRunCallback)) {
                    break 2;
                }

                $count++;

                if($count >= $this->maxRunCount) break 2;

            } while($count < $requiredRunCount);

        } while($run);
    }

    /*
     *
     */

    /**
     * Handles scheduling by setting the CRON jobs if scheduling is active, or deleting current jobs if scheduling is
     * disabled.
     */
    public function handleCronEvents(): void {
        // URL collection and post-crawling
        if(SettingService::isSchedulingActive()) {
            $this->scheduleEvents();
        } else {
            $this->removeURLCollectionAndCrawlingEvents();
        }

        // Recrawling
        if(SettingService::isRecrawlingActive()) {
            $this->scheduleRecrawlingEvent();
        } else {
            $this->removeRecrawlingEvent();
        }

        // Deleting
        if(SettingService::isDeletingActive()) {
            $this->scheduleDeletingEvent();
        } else {
            $this->removeDeletingEvent();
        }
    }

    /**
     * Schedule events with time intervals specified by the user
     */
    public function scheduleEvents(): void {
        $intervalCollectUrls = get_option(SettingKey::WPCC_INTERVAL_URL_COLLECTION);
        $intervalCrawlPosts  = get_option(SettingKey::WPCC_INTERVAL_POST_CRAWL);

        $this->scheduleEvent($this->eventCollectUrls, $intervalCollectUrls);
        $this->scheduleEvent($this->eventCrawlPost, $intervalCrawlPosts);
    }

    /**
     * Start scheduling for recrawling event with the time interval specified by the user
     */
    public function scheduleRecrawlingEvent(): void {
        $interval = get_option(SettingKey::WPCC_INTERVAL_POST_RECRAWL);

        $this->scheduleEvent($this->eventRecrawlPost, $interval);
    }

    /**
     * Start scheduling for deleting event with the time interval specified by the user
     */
    public function scheduleDeletingEvent(): void {
        $interval = get_option(SettingKey::WPCC_INTERVAL_POST_DELETE);

        $this->scheduleEvent($this->eventDeletePosts, $interval);
    }

    /**
     * Schedules an event after removes the old event, if it exists.
     *
     * @param string $eventName Name of the event
     * @param string $interval One of the registered CRON interval keys
     */
    private function scheduleEvent($eventName, $interval): void {
        // Try to remove the next schedule.
        $this->removeScheduledEvent($eventName);

        // Schedule the event
        if(!$timestamp = wp_get_schedule($eventName)) {
            wp_schedule_event(time() + 5, $interval, $eventName);
        }
    }

    /*
     *
     */

    /**
     * Removes scheduled events
     */
    public function removeURLCollectionAndCrawlingEvents(): void {
        $eventNames = [$this->eventCollectUrls, $this->eventCrawlPost];
        foreach($eventNames as $eventName) {
            $this->removeScheduledEvent($eventName);
        }
    }

    /**
     * Removes (disables) recrawling event.
     */
    public function removeRecrawlingEvent(): void {
        $this->removeScheduledEvent($this->eventRecrawlPost);
    }

    /**
     * Removes (disables) deleting event.
     */
    public function removeDeletingEvent(): void {
        $this->removeScheduledEvent($this->eventDeletePosts);
    }

    /**
     * Remove a scheduled event. i.e. disable the schedule for an event
     *
     * @param string $eventName Name of the event
     */
    private function removeScheduledEvent($eventName): void {
        if($timestamp = wp_next_scheduled($eventName)) {
            wp_unschedule_event($timestamp, $eventName);
        }
    }

    /**
     * @return array Structured as
     * <b>[ interval_key => [interval_description, interval_in_seconds], interval_key_2 => [ ... ], ... ]</b>
     */
    public function getIntervals() {
        if ($this->intervals) return $this->intervals;

        $this->intervals = [
            // Interval Name        Description                           Interval in Seconds
            '_wpcc_1_minute'    =>  [_wpcc('Every minute'),        60],
            '_wpcc_2_minutes'   =>  [_wpcc('Every 2 minutes'),     2 * 60],
            '_wpcc_3_minutes'   =>  [_wpcc('Every 3 minutes'),     3 * 60],
            '_wpcc_5_minutes'   =>  [_wpcc('Every 5 minutes'),     5 * 60],
            '_wpcc_10_minutes'  =>  [_wpcc('Every 10 minutes'),    10 * 60],
            '_wpcc_15_minutes'  =>  [_wpcc('Every 15 minutes'),    15 * 60],
            '_wpcc_20_minutes'  =>  [_wpcc('Every 20 minutes'),    20 * 60],
            '_wpcc_30_minutes'  =>  [_wpcc('Every 30 minutes'),    30 * 60],
            '_wpcc_45_minutes'  =>  [_wpcc('Every 45 minutes'),    45 * 60],
            '_wpcc_1_hour'      =>  [_wpcc('Every hour'),          60 * 60],
            '_wpcc_2_hours'     =>  [_wpcc('Every 2 hours'),       2 * 60 * 60],
            '_wpcc_3_hours'     =>  [_wpcc('Every 3 hours'),       3 * 60 * 60],
            '_wpcc_4_hours'     =>  [_wpcc('Every 4 hours'),       4 * 60 * 60],
            '_wpcc_6_hours'     =>  [_wpcc('Every 6 hours'),       6 * 60 * 60],
            '_wpcc_12_hours'    =>  [_wpcc('Twice a day'),         12 * 60 * 60],
            '_wpcc_1_day'       =>  [_wpcc('Once a day'),          24 * 60 * 60],
            '_wpcc_2_days'      =>  [_wpcc('Every 2 days'),        2 * 24 * 60 * 60],
            '_wpcc_1_week'      =>  [_wpcc('Once a week'),         7 * 24 * 60 * 60],
            '_wpcc_2_weeks'     =>  [_wpcc('Every 2 weeks'),       2 * 7 * 24 * 60 * 60],
            '_wpcc_1_month'     =>  [_wpcc('Once a month'),        4 * 7 * 24 * 60 * 60],
        ];

        return $this->intervals;
    }

    /**
     * Adds custom time intervals for CRON scheduling.
     */
    private function setCRONIntervals(): void {
        $intervals = $this->getIntervals();
        add_filter('cron_schedules', function($schedules) use ($intervals) {
            foreach($intervals as $name => $interval) {
                $schedules[$name] = [
                    'interval'  =>  $interval[1],
                    'display'   =>  $interval[0]
                ];
            }

            return $schedules;
        });

        Utils::validateCRON();
    }

    /**
     * Get active and published sites' IDs
     *
     * @param string $activeKey The meta key that stores a checkbox value indicating the site is active or not.
     * @return int[]            An array of active and published site IDs. If there is no active and published site ID,
     *                          then empty array will be returned.
     */
    private function getActiveSiteIds($activeKey = SettingKey::ACTIVE) {
        if(!$activeKey) return [];

        // If the result was cached before, return the cached result.
        if(isset($this->activeSiteIdsCache[$activeKey])) {
            return $this->activeSiteIdsCache[$activeKey];
        }

        $query = new WP_Query([
            'post_type'     =>  Environment::postType(),
            'meta_query'    => [
                [
                    'key'       => $activeKey,
                    'value'     => ['on', 1],
                    'compare'   => 'in',
                ]
            ],
            'fields'        =>  'ids',
            'post_status'   =>  'publish',
            'nopaging'      =>  true,
        ]);

        // Get currently active sites
        $posts = $query->get_posts();

        // Cache the result
        $this->activeSiteIdsCache[$activeKey] = $posts;

        return $posts; // @phpstan-ignore-line
    }

    /**
     * Invalidates the caches
     *
     * @since 1.12.0
     */
    public function invalidateCaches(): void {
        $this->activeSiteIdsCache = [];
    }

    /**
     * Get next site ID for a CRON event
     *
     * @param string $optionName Option key used to store last checked ID for the event
     * @return int|null The site ID or null if no site ID is found
     */
    public function getSiteIdForEvent($optionName) {
        // Get the active key for this $optionName
        $activeKey = SettingKey::ACTIVE;
        switch($optionName) {
            case Factory::postSaver()->optionLastRecrawledSiteId:
                $activeKey = SettingKey::ACTIVE_RECRAWLING;
                break;

            case $this->optionKeyLastPostDeletedSiteId:
                $activeKey = SettingKey::ACTIVE_POST_DELETING;
                break;

            default:
                $activeKey = SettingKey::ACTIVE;
        }

        // Get active site IDs
        $activeSites = $this->getActiveSiteIds($activeKey);

        // If there is no active site, then do not continue.
        if(empty($activeSites)) return null;

        // Get last checked site ID
        $lastCheckedSiteId = get_option($optionName);

        /** @noinspection PhpUnusedLocalVariableInspection */
        $siteIdToCheck = null;

        // If there is no last-checked site ID, then take the first active site in the active sites array
        if(!$lastCheckedSiteId) {
            if(static::$DEBUG) var_dump("Last checked site ID not found. Get the first active site's ID");
            $siteIdToCheck = $activeSites[0];

        // Otherwise, find the active site that comes after the last-checked site
        } else {
            // Find the active site that comes after the last-checked site. If the last checked site ID is the last
            // element in the array, then get the first element of it as site-id-to-check.

            $lastCheckedSiteIdPos = array_search($lastCheckedSiteId, $activeSites);
            if(is_int($lastCheckedSiteIdPos) && $lastCheckedSiteIdPos != sizeof($activeSites) - 1) {
                $siteIdToCheck = $activeSites[$lastCheckedSiteIdPos + 1];
            } else {
                $siteIdToCheck = $activeSites[0];
            }
        }

        return $siteIdToCheck;
    }

}

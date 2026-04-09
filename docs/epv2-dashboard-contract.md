# EPV2 Dashboard Contract

## Menu Structure

- Overview
- Sources
- Queue
- Settings
- Manual Mode
- Review
- Logs
- Runs

Source: `/var/www/europulse/public/wp-content/plugins/europulse-autopilot-v2/includes/admin/class-epv2-admin.php`

## Registered Admin Actions

- `epv2_save_settings`
- `epv2_save_source`
- `epv2_toggle_source`
- `epv2_delete_source`
- `epv2_import_recommended_sources`
- `epv2_delete_queue_items`
- `epv2_clear_queue`
- `epv2_update_queue_category`
- `epv2_run_collect`
- `epv2_run_process`
- `epv2_run_publish`
- `epv2_pause_automation`
- `epv2_resume_automation`
- `epv2_reset_stats`
- `epv2_prune_queue`
- `epv2_queue_to_publish`
- `epv2_publish_now`
- `epv2_review_ready_publish`
- `epv2_save_review`
- `epv2_regenerate_field`
- `epv2_manual_generate`
- `epv2_manual_submit`
- `epv2_test_source`

## Queue UI Blocks

- Live queue
- Review queue
- Ready to publish
- Published recent
- Published archive
- Filters by state/category/search/sort
- Countdown to next publish slot

## Review / Manual Features

- Regenerate title / excerpt / content
- Save review payload
- Move to ready publish
- Publish now
- Manual import and manual AI rewrite

## EPV3 Requirement

`EPV3` dashboard must not ship with fewer operator capabilities than this contract.

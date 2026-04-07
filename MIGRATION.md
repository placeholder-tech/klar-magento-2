# Migration Guide: ICT_Klar to PlaceholderTech_Klar

This guide covers migrating from the old `ltd-iconcept/magento2-klar` module (`ICT_Klar`) to the new `placeholder-tech/klar-magento-2` module (`PlaceholderTech_Klar`).

## What Changed

| | Old | New |
|---|---|---|
| Composer package | `ltd-iconcept/magento2-klar` | `placeholder-tech/klar-magento-2` |
| PHP namespace | `ICT\Klar` | `PlaceholderTech\Klar` |
| Magento module name | `ICT_Klar` | `PlaceholderTech_Klar` |
| GitHub repository | `ltd-iconcept/magento2-klar` | `placeholder-tech/klar-magento-2` |

## What Stays the Same

- **Configuration values** are preserved. Both modules use the same config paths (`klar/integration/*`), so your API URL, API version, API token, and all other settings carry over automatically.
- **Database table** `klar_order_attributes` is unchanged. Your existing order sync tracking data is preserved.
- **Message queue topic** `klar.order.synchronization` is unchanged.
- **CLI command** `bin/magento klar:order` works the same way.

## Migration Steps

### 1. Enable Maintenance Mode

```bash
bin/magento maintenance:enable
```

### 2. Export Your Current Configuration (Optional but Recommended)

Note down your current settings at **Stores > Configuration > Sales > Klar** (API URL, API Version, API Token, Send Email, Public Key) as a safety measure. These values should be preserved automatically, but it's good practice to have a backup.

### 3. Disable and Remove the Old Module

```bash
bin/magento module:disable ICT_Klar
composer remove ltd-iconcept/magento2-klar
```

### 4. Remove the Old Repository Reference

Edit your project's `composer.json` and remove the old repository entry if present:

```json
{
    "type": "vcs",
    "url": "https://github.com/ltd-iconcept/magento2-klar"
}
```

### 5. Add the New Repository and Install

Add the new repository to your project's `composer.json`:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/placeholder-tech/klar-magento-2"
    }
]
```

Then install the new module:

```bash
composer require placeholder-tech/klar-magento-2:^1.0.0
```

### 6. Enable the New Module

```bash
bin/magento module:enable PlaceholderTech_Klar
```

### 7. Clean Up the Old Module Entry from the Database

The old module entry needs to be removed from Magento's `setup_module` table, otherwise Magento may report conflicts. Run the following SQL against your Magento database:

```sql
DELETE FROM setup_module WHERE module = 'ICT_Klar';
```

### 8. Run Setup and Recompile

```bash
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy
bin/magento cache:clean
```

### 9. Update Admin Role Permissions

The ACL resource identifiers changed from `ICT_Klar::*` to `PlaceholderTech_Klar::*`. If you have custom admin roles (not the default full-access admin), you need to re-grant Klar permissions:

1. Go to **System > Permissions > User Roles**
2. Edit each role that previously had Klar access
3. Under **Role Resources**, locate and enable **Klar Integration > Configuration**
4. Save the role

### 10. Verify Configuration

1. Navigate to **Stores > Configuration > Sales > Klar**
2. Confirm your API URL, API Version, and API Token are still set correctly
3. If any values are missing, re-enter them (refer to the backup from step 2)

### 11. Disable Maintenance Mode

```bash
bin/magento maintenance:disable
```

### 12. Verify the Integration

Test that orders are being sent to Klar:

```bash
# Debug-dump a recent order to verify the payload is correct
bin/magento klar:order <order_id> -d

# Send a test order
bin/magento klar:order <order_id>
```

Check the Klar configuration page in the admin panel — the **Orders Status** section should show the latest sync information.

## Troubleshooting

### "Module 'ICT_Klar' is not installed" errors during setup:upgrade
Make sure you ran step 7 (removing the old module entry from `setup_module`).

### Configuration values are missing after migration
The config paths are identical, so values should carry over. If they're missing, the `core_config_data` table may have been cleared. Re-enter your settings at **Stores > Configuration > Sales > Klar**.

### Admin user cannot access Klar configuration
See step 9 — the ACL resources changed. Re-grant permissions under **System > Permissions > User Roles**.

### Queue messages are stuck
Clear old queue messages and restart the consumer:

```bash
bin/magento queue:consumers:start klar.order.synchronization --max-messages=0
```

Make sure `env.php` still has the consumer configured:

```php
'cron_consumers_runner' => [
    'cron_run' => true,
    'consumers' => [
        'klar.order.synchronization'
    ]
]
```

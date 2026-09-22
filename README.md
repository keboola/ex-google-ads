# Google Ads Extractor

This extractor allows you to import data from Google Ads. If you do not have a Google Ads manager account, follow this [guide](https://support.google.com/google-ads/answer/7459399?hl=en) to set it up.

# Example configuration
```json
{
  "parameters": {
    "customerId": "111111111",
    "name": "test-report",
    "query": "SELECT campaign.id, campaign.name, metrics.clicks, metrics.impressions FROM campaign"
  },
  "image_parameters": {
    "#developer_token": "developertoken"
  },
  "authorization": {
    "oauth_api": {
      "credentials": {
        "#data": "{\"access_token\": ...}",
        "#appSecret": "appsecret",
        "appKey": "appkey"
      }
    }
  }
}
```

## Configuration

The configuration contains the following parameters:

- **`continueOnFailure`** (boolean, default `true`) — With many ad accounts, the extractor continues importing from other accounts when one account fails. The job still fails if every account fails. Set `false` to fail on the first error.

- **`rewriteDeprecatedFields`** (boolean, default `true`, advanced) — Automatically rewrites deprecated GAQL field names (removed in Google Ads API v22/v23, e.g. `metrics.video_views`, `campaign.start_date`) to their v25 equivalents and preserves the output table's original column names and value types. Set `false` once you have migrated your query to v25 field names to receive the native v25 column names.

**Note:** If every account fails in a run, no tables are imported at all, including the `customer` and `campaign` tables.

## Development
 
Clone this repository and init the workspace with following command:

```shell
git clone https://github.com/keboola/ex-google-ads.git
cd ex-google-ads
docker-compose build
docker-compose run --rm dev composer install --no-scripts
```

Set up envs and fill it with credentials:
```shell
cp .env.dist .env
```

Run the test suite using this command:
```shell
docker-compose run --rm dev composer tests
```
 
# Integration

For information about deployment and integration with KBC, please refer to the [deployment section of developers documentation](https://developers.keboola.com/extend/component/deployment/) 

## License

MIT licensed, see [LICENSE](./LICENSE) file.

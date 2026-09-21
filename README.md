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

## Error handling

The extractor is built for manager accounts that hold many client accounts, where single accounts
are regularly unreadable: closed, suspended, or not a Google Ads account at all.

- **Some accounts fail.** The extractor logs an error for each failed account and continues with
  the remaining accounts. The job finishes successfully, so one dead account does not cost you the
  data of all the others. Read the job log to see which accounts were skipped.
- **Every processed account fails.** No report data was downloaded, so the job fails with exit
  code 1. This is what happens when the configured `query` is not valid, for example when it asks
  for a field that the Google Ads API no longer accepts. The `customer` and `campaign` tables are
  written before the report is requested, but a failed job loads no tables at all, so these two
  tables are also not imported by such a run.

A run that processes no account at all, for example a configuration that points only at manager
accounts, still finishes successfully.

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

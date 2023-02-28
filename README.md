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

# silverstripe-sesbouncehandler
test the module:
```sh
curl -X POST \
  --url 'localhost:8081/ses-bounce-handler/' \
  -H "x-amz-sns-message-type: Bounce" -d @doc/testmail.json
```
this should throw som SES Validation errors, but it shows that the module validation is working properly.

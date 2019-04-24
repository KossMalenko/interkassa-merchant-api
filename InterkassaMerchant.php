<?php


namespace common\components;

use Yii;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\httpclient\Client;
use yii\web\HttpException;

class InterkassaMerchant
{
    public $co_id;
    public $secret_key;
    public $test_key;
    public $sign_algo = 'md5';
    public $api_user_id;
    public $api_user_key;

    const URL = 'https://sci.interkassa.com/';
    const URL_API = 'https://api.interkassa.com/v1/';

    public function __construct($config)
    {
        $this->co_id = $config['co_id'];
        $this->secret_key = $config['secret_key'];
        $this->test_key = $config['test_key'];
        $this->api_user_id = $config['api_user_id'];
        $this->api_user_key = $config['api_user_key'];
    }

    /**
     * @param array $params
     * @return string
     */
    public function generateSign(array $params)
    {
        $pairs = [];

        foreach ($params as $key => $val)
        {
            if (strpos($key, 'ik_') === 0 && $key !== 'ik_sign')
                $pairs[$key] = $val;
        }

        uksort($pairs, function($a, $b) use ($pairs) {
            $result = strcmp($a, $b);

            if ($result === 0)
                $result = strcmp($pairs[$a], $pairs[$b]);

            return $result;
        });

        array_push($pairs, YII_ENV == 'dev' ? $this->test_key
            : $this->secret_key);

        return base64_encode(hash($this->sign_algo, implode(":", $pairs), true));
    }

    /**
     * Example params:
     * params => [ik_pm_no, ik_am, ik_desc]
     * ik_pm_no - payment id
     * ik_am - amount
     * ik_desc - payment description
     *
     * @param array $params
     * @return \yii\console\Response|\yii\web\Response
     */
    public function payment(array $params)
    {
        if (!is_array($params))
            throw new \InvalidArgumentException('Params must be array');

        $params['ik_co_id'] = $this->co_id;

        return Yii::$app->response->redirect(self::URL . '?' .http_build_query($params));
    }

    /**
     * @param int $id
     * @param string $purse_name
     * @param string $payway_name
     * @param array $details
     * @param float $amount
     * @param string $calcKey
     * @param string $action
     * @return mixed
     * @throws Exception
     * @throws HttpException
     * @throws InvalidConfigException
     * @throws \yii\httpclient\Exception
     */
    public function withdraw(int $id, string $purse_name, string $payway_name, array $details, float $amount,
                             string $calcKey = 'ikPayerPrice', string $action = 'calc')
    {
        $purses = $this->getPurses();
        $purse = null;

        foreach ($purses as $_purse)
        {
            if ($_purse->name == $purse_name)
            {
                $purse = $_purse;
                break;
            }
        }

        if ($purse === null)
            throw new HttpException("Purse not found");

        if ($purse->balance < $amount)
            throw new HttpException("Balance in purse ({$purse->balance}) less withdraw amount ({$amount}).");

        $payways = $this->getOutputPayways();
        $payway = null;

        foreach ($payways as $_payway)
        {
            if ($_payway->als == $payway_name)
            {
                $payway = $_payway;
                break;
            }
        }

        if ($payway === null)
            throw new HttpException("Payway not found");

        try {
            $result = $this->createWithdraw(
                $amount,
                $payway->id,
                $details,
                $purse->id,
                $calcKey,
                $action,
                $id
            );

            if ($result->{'@resultCode'} == 0)
                return $result->transaction;
            else
                throw new HttpException($result->{'@resultMessage'});
        } catch (HttpException $e) {
            throw new HttpException('Http exception: ' . $e->getMessage());
        }
    }

    /**
     * get a list of accounts available to the user
     *
     * @return |null
     * @throws HttpException
     * @throws InvalidConfigException
     * @throws \yii\httpclient\Exception
     */
    public function getAccounts()
    {
        return $this->request('GET', 'account');
    }

    /**
     * get a list of cash registers associated with the account.
     * The response transmits information on the cash registers, including the available payment directions to enter
     *
     * @return |null
     * @throws Exception
     * @throws HttpException
     * @throws InvalidConfigException
     * @throws \yii\httpclient\Exception
     */
    public function getCheckout()
    {
        return self::request('GET', 'checkout', $this->getLkApiAccountId());
    }

    /**
     * get a list of purses associated with your account, with their parameters.
     *
     * @return |null
     * @throws Exception
     * @throws HttpException
     * @throws InvalidConfigException
     * @throws \yii\httpclient\Exception
     */
    public function getPurses()
    {
        return $this->request('GET', 'purse', $this->getLkApiAccountId());
    }

    /**
     * receive unloading payments. With each payment, its identifier is transmitted in the IR system,
     * the time of creation and a number of other parameters, including the status of the payment in the “state” field
     *
     * @return |null
     * @throws Exception
     * @throws HttpException
     * @throws InvalidConfigException
     * @throws \yii\httpclient\Exception
     */
    public function getCoInvoices()
    {
        return $this->request('GET', 'co-invoice', $this->getLkApiAccountId());
    }

    /**
     * get a list of implemented conclusions (GET), information on a specific output (GET)
     *
     * @return |null
     * @throws Exception
     * @throws HttpException
     * @throws InvalidConfigException
     * @throws \yii\httpclient\Exception
     */
    public function getWithdraws()
    {
        return $this->request('GET', 'withdraw/', $this->getLkApiAccountId());
    }

    /**
     * get withdraw by id
     *
     * @param $id
     * @return |null
     * @throws Exception
     * @throws HttpException
     * @throws InvalidConfigException
     * @throws \yii\httpclient\Exception
     */
    public function getWithdraw($id)
    {
        return $this->request('GET', 'withdraw/'.$id, $this->getLkApiAccountId());
    }

    /**
     * @param $amount
     * @param $paywayId
     * @param $details
     * @param $purseId
     * @param $calcKey
     * @param $action
     * @param $paymentNo
     * @return |null
     * @throws Exception
     * @throws HttpException
     * @throws InvalidConfigException
     * @throws \yii\httpclient\Exception
     */
    public function createWithdraw($amount, $paywayId, $details, $purseId, $calcKey, $action, $paymentNo)
    {
        return $this->request('POST', 'withdraw', $this->getLkApiAccountId(), [
            'amount' => $amount,
            'paywayId' => $paywayId,
            'details' => $details,
            'purseId' => $purseId,
            'calcKey' => $calcKey,
            'action' => $action,
            'paymentNo' => $paymentNo,
        ]);
    }

    /**
     * @return mixed|null
     * @throws HttpException
     * @throws InvalidConfigException
     * @throws \yii\httpclient\Exception
     */
    public function getCurrencies()
    {
        $cache = Yii::$app->cache;
        if (($data = $cache->get('interkassa.currency')) !== null)
            return $data;
        else
        {
            $response = $this->request('GET', 'currency');
            $cache->set('interkassa.currency', $response, 86400);
            return $response;
        }
    }

    /**
     * get a list of payment entry directions included in the system
     *
     * @return mixed|null
     * @throws HttpException
     * @throws InvalidConfigException
     * @throws \yii\httpclient\Exception
     */
    public function getInputPayways()
    {
        $cache = Yii::$app->cache;
        if (($data = $cache->get('interkassa.input_payways')) !== null)
            return $data;
        else
        {
            $response = $this->request('GET', 'paysystem-input-payway');
            $cache->set('interkassa.input_payways', $response, 86400);
            return $response;
        }
    }

    /**
     * get a list of payment directions for withdrawal included in the IC system.
     * For each direction, its id is returned to the IR, alias,
     * as well as an array of mandatory key details in the prm element. These keys are used to create the output.
     *
     * @return mixed|null
     * @throws HttpException
     * @throws InvalidConfigException
     * @throws \yii\httpclient\Exception
     */
    public function getOutputPayways()
    {
        $cache = Yii::$app->cache;
        if (($data = $cache->get('interkassa.output_payways')) !== null)
            return $data;
        else
        {
            $response = $this->request('GET', 'paysystem-output-payway', null);
            $cache->set('interkassa.output_payways', $response, 86400);
            return $response;
        }
    }

    /**
     * @param $http_method
     * @param $method
     * @param null $lk_api_account_id
     * @param array $data
     * @return |null
     * @throws HttpException
     * @throws InvalidConfigException
     * @throws \yii\httpclient\Exception
     */
    public function request($http_method, $method, $lk_api_account_id = null, $data = [])
    {
        $client = new Client();
        $request = $client->createRequest()
            ->setMethod($http_method)
            ->setUrl(self::URL_API . $method);

        if (count($data) > 0)
            $request->setData($data);

        if ($lk_api_account_id !== null)
            $request->addHeaders(['Ik-Api-Account-Id' => $lk_api_account_id]);

        $request->addHeaders(['Authorization' => 'Basic ' . base64_encode($this->api_user_id . ':' . $this->api_user_key)]);

        $response = $request->send();

        if ($response->isOk ** $response->data['code'] == 0) {
            return $response->data['data'] ?? null;
        } else {
            throw new HttpException($response->statusCode);
        }

    }

    /**
     * @return string
     * @throws Exception
     */
    private function getLkApiAccountId() : string
    {
        $cache = Yii::$app->cache;
        if (($lk_api_account_id = $cache->get('interkassa.lk_api_account_id')) !== null)
            return $lk_api_account_id;
        else
        {
            $accounts_info = $this->getAccounts();
            $lk_api_account_id = null;

            foreach ($accounts_info as $account_info)
            {
                if ($account_info->tp == 'b')
                {
                    $lk_api_account_id = $account_info->_id;
                    break;
                }
            }

            if ($lk_api_account_id === null)
                throw new Exception("Business id not found");

            $cache->set('interkassa.lk_api_account_id', $lk_api_account_id, 86400);
            return $lk_api_account_id;
        }
    }
}
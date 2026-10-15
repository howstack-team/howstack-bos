<?php

declare(strict_types=1);

namespace plugins\identity\tencent_faceid;

use app\shared\plugin\contract\IdentityAdapterInterface;
use app\shared\plugin\contract\HttpClientInterface;
use app\shared\plugin\configuration\ChannelFields as Fields;
use app\shared\plugin\dto\Configuration;
use app\shared\plugin\dto\identity\IdentityRequest;
use app\shared\plugin\dto\identity\IdentityReference;
use app\shared\plugin\dto\identity\IdentityResult;
use app\shared\plugin\http\HttpClient;
use app\shared\plugin\cloud\TencentCloudApi;
use TencentCloud\Faceid\V20180301\Models\DetectAuthRequest;
use TencentCloud\Faceid\V20180301\Models\GetDetectInfoEnhancedRequest;
use Throwable;

/** H5 活体核身；只查询文本结果，不请求或返回照片、视频与证件材料。 */
final readonly class IdentityAdapter implements IdentityAdapterInterface
{
    /**
     * @param HttpClientInterface $http 单次 HTTP 能力
     */
    public function __construct(private HttpClientInterface $http = new HttpClient())
    {
    }
    /** @inheritDoc */
    public function validateConfiguration(Configuration $configuration): Configuration
    {
        $v = $configuration->values;
        return new Configuration([
            'secret_id' => Fields::text($v, 'secret_id', 128),
            'secret_key' => Fields::text($v, 'secret_key', 128),
            'rule_id' => Fields::text($v, 'rule_id', 128),
            'region' => Fields::region($v),
        ]);
    }

    /** @inheritDoc */
    public function initialize(IdentityRequest $request, Configuration $configuration): IdentityResult
    {
        $c = $this->validateConfiguration($configuration)->values;
        $model = new DetectAuthRequest();
        $model->fromJsonString(json_encode([
            'RuleId' => $c['rule_id'], 'IdCard' => $request->identityNumber,
            'Name' => $request->name, 'RedirectUrl' => $request->returnUrl,
        ], JSON_THROW_ON_ERROR));
        try {
            $data = (new TencentCloudApi($this->http))->request('faceid', '2018-03-01', 'DetectAuth', $model, $c['secret_id'], $c['secret_key'], $c['region']);
            if (isset($data['Error'])) {
                return new IdentityResult('failed', $request->requestNo);
            }
            if (!is_string($data['BizToken'] ?? null) || !is_string($data['Url'] ?? null)) {
                return new IdentityResult('unknown', $request->requestNo);
            }
            return new IdentityResult('pending', $request->requestNo, $data['BizToken'], $data['Url']);
        } catch (Throwable) {
            return new IdentityResult('unknown', $request->requestNo);
        }
    }

    /** @inheritDoc */
    public function query(IdentityReference $reference, Configuration $configuration): IdentityResult
    {
        $c = $this->validateConfiguration($configuration)->values;
        $model = new GetDetectInfoEnhancedRequest();
        $model->fromJsonString(json_encode(['RuleId' => $c['rule_id'], 'BizToken' => $reference->certifyId, 'InfoType' => '1'], JSON_THROW_ON_ERROR));
        try {
            $data = (new TencentCloudApi($this->http))->request('faceid', '2018-03-01', 'GetDetectInfoEnhanced', $model, $c['secret_id'], $c['secret_key'], $c['region']);
            $code = $data['Text']['ErrCode'] ?? null;
            $status = is_int($code) ? ($code === 0 ? 'verified' : 'rejected') : 'unknown';
            return new IdentityResult($status, $reference->requestNo, $reference->certifyId);
        } catch (Throwable) {
            return new IdentityResult('unknown', $reference->requestNo, $reference->certifyId);
        }
    }
}

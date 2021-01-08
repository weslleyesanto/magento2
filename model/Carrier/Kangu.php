<?php

    namespace Kangu\Shipping\Model\Carrier;

    use Magento\Framework\App\Config\ScopeConfigInterface;
    use Magento\Framework\DataObject;
    use Magento\Shipping\Model\Carrier\AbstractCarrier;
    use Magento\Shipping\Model\Carrier\CarrierInterface;
    use Magento\Shipping\Model\Config;
    use Magento\Shipping\Model\Rate\ResultFactory;
    use Magento\Store\Model\ScopeInterface;
    use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
    use Magento\Quote\Model\Quote\Address\RateResult\Method;
    use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
    use Magento\Quote\Model\Quote\Address\RateRequest;
    use Psr\Log\LoggerInterface;

    class Kangu extends AbstractCarrier implements CarrierInterface {

        protected $_code = 'kangu';
        protected $_isFixed = true;
        protected $_rateResultFactory;
        protected $_rateMethodFactory;
        protected $_servicos;
        
        public function __construct(
        ScopeConfigInterface $scopeConfig, ErrorFactory $rateErrorFactory, LoggerInterface $logger, ResultFactory $rateResultFactory, MethodFactory $rateMethodFactory, array $data = []
        ) {
            $this->_rateResultFactory = $rateResultFactory;
            $this->_rateMethodFactory = $rateMethodFactory;
            parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
            
            $this->_servicos = array(
                'E' => __('Regular Delivery'),
                'X' => __('Express Delivery'),
            );
        }
        
        public function getAllowedMethods() {
            return $this->_servicos;
        }

        public function collectRates(RateRequest $request) {
            if (!$this->getConfigFlag('active')) {
                return false;
            }

            $params = array(
                'postcode_orig' => $request->getPostcode(),
                'postcode_dest' => $request->getDestPostcode(),
                'country_id_orig' => $request->getCountryId(),
                'country_id_dest' => $request->getDestCountryId(),
                'package_value' => $request->getPackageValue(),
                'package_weight' => $request->getPackageWeight(),
                'defaultheight' => $this->getConfigData('defaultheight'),
                'defaultwidth' => $this->getConfigData('defaultwidth'),
                'defaultlength' => $this->getConfigData('defaultlength'),
            );

            $items = $request->getAllItems();
            foreach($items as $item){
                $params['volumes'][] = $item->getProduct()->getData();
            }
            $client = new \Zend_Http_Client();
            $client->setUri('https://portal.kangu.com.br/tms/transporte/magento-simular');

            $token = $this->getConfigData('token');

            if(!$token){
                throw new \Magento\Framework\Exception\LocalizedException(__('No access token found, please add a valid token on your configurations'));
            }

            $client->setHeaders('token', $token);
            
            $client->setRawData(json_encode($params));
            $json = $client->request('POST')->getBody(); 
            
            $fretes = json_decode($json);

            if(!$fretes){
                return false;
            }

            $result = $this->_rateResultFactory->create();
            
            foreach($fretes as $frete){
                if(!$frete){
                    continue;
                }

                if (property_exists($frete, 'mensagem')) {
                    if($frete->mensagem){
                        continue;
                    }
                }

                if (property_exists($frete, 'error')) {
                    if($frete->error->mensagem){
                        continue;
                    }
                }

                $method = $this->_rateMethodFactory->create();
                $method->setCarrier($this->_code);
                $method->setCarrierTitle($this->getConfigData('title'));
                $method->setMethod($frete->servico  . '_' . $frete->cnpjTransp);
                
                $method->setMethodTitle($frete->descricao . ' (' . $frete->prazoEnt . ' ' . __('Bussiness Days') . ')');
                
                if ($request->getFreeShipping()) {
                    $method->setPrice(0);
                    $method->setCost(0);
                } else {
                    $method->setPrice($frete->vlrFrete);
                    $method->setCost($frete->vlrFrete);
                }

                $result->append($method);
            }
            return $result;
        }

        public function requestToShipment($request) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Unable to print label. Try printing it on our website:') . ' ' . 'https://www.kangu.com.br/impressao-etiqueta'
            );
            
            return false;
        }
        
        public function isShippingLabelsAvailable()
        {
            return true;
        }
        
        public function isZipCodeRequired($countryId = null)
        {
            return true;
        }
        
        public function isTrackingAvailable() {
            return false;
        }
    }
    
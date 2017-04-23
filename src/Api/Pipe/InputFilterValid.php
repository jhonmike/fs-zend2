<?php

namespace Api\Pipe;

use Interop\Container\ContainerInterface;
use Interop\Http\ServerMiddleware\DelegateInterface;
use Interop\Http\ServerMiddleware\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Locale;
use Zend\Diactoros\Response\JsonResponse;
use Zend\InputFilter\Factory;
use Zend\Expressive\Router\RouterInterface;

class InputFilterValid implements MiddlewareInterface
{
    private $router;
    private $config;

    public static function factory(ContainerInterface $container)
    {
        return new self(
            $container->get(RouterInterface::class),
            $container->get('config')
        );
    }

    public function __construct(RouterInterface $router, array $config)
    {
        $this->router = $router;
        $this->config = $config;
    }

    public function process(ServerRequestInterface $request, DelegateInterface $delegate)
    {
        $router = $this->router->match($request)->getMatchedRouteName();
        $method = $request->getServerParams()['REQUEST_METHOD'] ?? 'NOT_METHOD';
        $lang = $request->getServerParams()['HTTP_ACCEPT_LANGUAGE'] ?? 'pt-BR';
        Locale::setDefault($this->parseLang($lang));

        $config = $this->config['routes'][$router] ?? [];
        if (!isset($config['parameters']))
            return $delegate->process($request->withAttribute('config', $config));

        $parameters = $this->parseParameters($config['parameters']);

        $factory = new Factory();
        $inputFilter = $factory->createInputFilter($parameters);
        if (in_array($method, ['GET', 'DELETE'], true))
            $inputFilter->setData($request->getQueryParams());
        if (in_array($method, ['POST', 'PUT'], true))
            $inputFilter->setData($request->getParsedBody());

        if ($inputFilter->isValid()) {
            if (in_array($method, ['GET', 'DELETE'], true))
                $request = $request->withQueryParams($inputFilter->getValues());
            if (in_array($method, ['POST', 'PUT', 'PATCH'], true))
                $request = $request->withParsedBody($inputFilter->getValues());
            return $delegate->process($request->withAttribute('config', $config));
        }

        $errors = [];
        foreach ($inputFilter->getInvalidInput() as $key => $error) {
            $errors[$key] = $error->getMessages();
        }
        return new JsonResponse($errors, 500);
    }

    private function parseLang($httpLang) : string
    {
        $languages = explode(',', $httpLang);
        $lang = str_replace('-', '_', $languages[0]);
        return $lang;
    }

    private function parseParameters(array $parameters) : array
    {
        if (!array_key_exists('inputFilter', $parameters))
            return $parameters;

        $nameFilters = $parameters['inputFilter']['name'];
        unset($parameters['inputFilter']);
        if (!isset($this->config['inputFilter'][$nameFilters]))
            return $parameters;

        return array_merge($parameters, $this->config['inputFilter'][$nameFilters]);
    }
}

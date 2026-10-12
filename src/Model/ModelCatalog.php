<?php

declare(strict_types=1);

namespace Ldkafka\Ai\Model;

use Ldkafka\Ai\Exception\ConfigurationException;

use function array_keys;
use function is_array;
use function is_int;
use function is_string;

/**
 * The configured models by name.
 */
final class ModelCatalog
{
    /**
     * @param array<string, ModelProfile> $models Profiles by catalog name.
     * @param string $default The name used when a request names no model.
     * @throws ConfigurationException When the default is not in the catalog.
     */
    public function __construct(private array $models, private string $default)
    {
        if (!isset($this->models[$default])) {
            throw new ConfigurationException('The default model "' . $default . '" is not in the model catalog.');
        }
    }

    /**
     * Build the catalog from the params `models` map.
     *
     * @param array<mixed> $models Keys are catalog names; values hold provider, id, capabilities,
     *        maxContext, maxOutput, maxTokens, price and options.
     * @throws ConfigurationException When an entry is invalid.
     */
    public static function fromConfig(array $models, string $default): self
    {
        $profiles = [];
        foreach ($models as $name => $config) {
            if (!is_string($name) || $name === '' || !is_array($config)) {
                throw new ConfigurationException('Each model needs a name and a config array.');
            }
            $provider = $config['provider'] ?? null;
            if (!is_string($provider) || $provider === '') {
                throw new ConfigurationException('Model "' . $name . '" needs a provider.');
            }
            $id = $config['id'] ?? $name;
            if (!is_string($id) || $id === '') {
                throw new ConfigurationException('Model "' . $name . '" has an invalid id.');
            }
            $capabilities = [];
            foreach (is_array($config['capabilities'] ?? null) ? $config['capabilities'] : [] as $capability) {
                $value = is_string($capability) ? Capability::tryFrom($capability) : null;
                if ($value === null) {
                    throw new ConfigurationException('Model "' . $name . '" lists an unknown capability.');
                }
                $capabilities[] = $value;
            }
            $price = $config['price'] ?? null;
            if ($price !== null && !is_array($price)) {
                throw new ConfigurationException('Model "' . $name . '" has an invalid price.');
            }
            $options = [];
            foreach (is_array($config['options'] ?? null) ? $config['options'] : [] as $key => $value) {
                $options[(string) $key] = $value;
            }
            $profiles[$name] = new ModelProfile(
                $name,
                $provider,
                $id,
                $capabilities,
                self::positiveInt($config, 'maxContext', $name),
                self::positiveInt($config, 'maxOutput', $name),
                self::positiveInt($config, 'maxTokens', $name),
                $price === null ? null : Price::fromConfig($price),
                $options,
            );
        }

        return new self($profiles, $default);
    }

    /**
     * The profile for a catalog name; '' = the default.
     *
     * @throws ConfigurationException When the name is not in the catalog.
     */
    public function get(string $name = ''): ModelProfile
    {
        $name = $name === '' ? $this->default : $name;
        if (!isset($this->models[$name])) {
            throw new ConfigurationException('Model "' . $name . '" is not in the model catalog.');
        }

        return $this->models[$name];
    }

    /**
     * The catalog names.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->models);
    }

    /**
     * @param array<mixed> $config
     * @throws ConfigurationException
     */
    private static function positiveInt(array $config, string $key, string $name): int
    {
        $value = $config[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new ConfigurationException('Model "' . $name . '" needs a positive integer ' . $key . '.');
        }

        return $value;
    }
}

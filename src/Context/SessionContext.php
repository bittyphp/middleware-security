<?php

namespace Bitty\Security\Context;

use Bitty\Http\Session\SessionInterface;
use Bitty\Security\Context\ContextInterface;
use Psr\Http\Message\ServerRequestInterface;

class SessionContext implements ContextInterface
{
    /**
     * @var SessionInterface
     */
    private $session = null;

    /**
     * @var string
     */
    private $name = null;

    /**
     * @var array[]
     */
    private $paths = null;

    /**
     * @var mixed[]
     */
    private $config = null;

    /**
     * @param SessionInterface $session
     * @param string $name
     * @param array[] $paths Formatted as [pattern => [role, ...]]
     * @param mixed[] $config
     */
    public function __construct(
        SessionInterface $session,
        string $name,
        array $paths,
        array $config = []
    ) {
        $this->session = $session;
        $this->name    = $name;
        $this->paths   = $paths;
        $this->config  = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * {@inheritDoc}
     */
    public function isDefault(): bool
    {
        return (bool) $this->config['default'];
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $name, $value): void
    {
        if (!$this->session->isStarted()) {
            $this->session->start();
        }

        if ($name === 'user') {
            $now = time();
            $this->doSet('destroy', $now + $this->config['destroy.delay']);
            $this->session->regenerate();
            $this->doRemove('destroy');
            $this->doSet('login', $now);
            $this->doSet('active', $now);
            $this->doSet('expires', $now + $this->config['ttl']);
        }

        $this->doSet($name, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $name, $default = null)
    {
        if (!$this->session->isStarted()) {
            $this->session->start();
        }

        if ($name === 'user') {
            $now     = time();
            $expires = $this->doGet('expires', 0);
            $destroy = $this->doGet('destroy', INF);
            $active  = $this->doGet('active', 0) + ($this->config['timeout'] ?: INF);
            $clear   = min($expires, $destroy, $active);

            if ($now > $clear) {
                // This session should be destroyed.
                // Clear out all data to prevent unauthorized use.
                $this->doClear();
            } else {
                // Update last active time.
                $this->doSet('active', $now);
            }
        }

        return $this->doGet($name, $default);
    }

    /**
     * {@inheritDoc}
     */
    public function remove(string $name): void
    {
        if (!$this->session->isStarted()) {
            $this->session->start();
        }

        $this->doRemove($name);
    }

    /**
     * {@inheritDoc}
     */
    public function clear(): void
    {
        if (!$this->session->isStarted()) {
            $this->session->start();
        }

        $this->doClear();
    }

    /**
     * {@inheritDoc}
     */
    public function isShielded(ServerRequestInterface $request): bool
    {
        $roles = $this->getRoles($request);

        return !empty($roles);
    }

    /**
     * {@inheritDoc}
     */
    public function getRoles(ServerRequestInterface $request): array
    {
        $path = $request->getUri()->getPath();
        foreach ($this->paths as $pattern => $roles) {
            if (preg_match("`$pattern`", $path)) {
                return $roles;
            }
        }

        return [];
    }

    /**
     * Gets the default configuration settings.
     *
     * @return mixed[]
     */
    public function getDefaultConfig(): array
    {
        return [
            // Whether or not this is the default context.
            'default' => true,

            // How long (in seconds) sessions are good for.
            // Defaults to 24 hours.
            'ttl' => 86400,

            // Timeout (in seconds) to invalidate a session after no activity.
            // Defaults to zero (disabled).
            'timeout' => 0,

            // Delay (in seconds) to wait before destroying an old session.
            // Sessions are flagged as "destroyed" during re-authentication.
            // Allows for a network lag in asynchronous applications.
            'destroy.delay' => 30,
        ];
    }

    /**
     * @param string $key
     * @param mixed $value
     */
    private function doSet(string $key, $value): void
    {
        $this->session->set($this->name.'/'.$key, $value);
    }

    /**
     * @param string $key
     * @param mixed $default
     *
     * @return mixed
     */
    private function doGet(string $key, $default = null)
    {
        return $this->session->get($this->name.'/'.$key, $default);
    }

    /**
     * @param string $key
     */
    private function doRemove(string $key): void
    {
        $this->session->remove($this->name.'/'.$key);
    }

    /**
     * Clears out data for this context only.
     */
    private function doClear(): void
    {
        foreach ($this->session->all() as $key => $value) {
            if (substr($key, 0, strlen($this->name.'/')) !== $this->name.'/') {
                continue;
            }

            $this->session->remove($key);
        }
    }
}

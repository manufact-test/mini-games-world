<?php
declare(strict_types=1);

final class StagingOperationDefinition
{
    private Closure $handler;

    public function __construct(
        private string $id,
        private string $build,
        callable $handler
    ) {
        $this->id = strtolower(trim($this->id));
        $this->build = trim($this->build);
        if (preg_match('/^[a-z0-9][a-z0-9._-]{2,190}$/', $this->id) !== 1) {
            throw new InvalidArgumentException('Staging operation ID is invalid.');
        }
        if ($this->build === '' || strlen($this->build) > 191) {
            throw new InvalidArgumentException('Staging operation build is invalid.');
        }
        $this->handler = Closure::fromCallable($handler);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function build(): string
    {
        return $this->build;
    }

    public function execute(): array
    {
        $result = ($this->handler)();
        if (!is_array($result)) {
            throw new RuntimeException('Staging operation must return an array report.');
        }
        return $result;
    }
}

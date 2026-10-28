<?php

declare(strict_types=1);

namespace GeoLocator\Config;

/**
 * Compatibility wrapper for the former project-path resolver API.
 *
 * Kept for development snapshots prior to 1.0; resolution now uses package-root
 * semantics, including when the old projectRoot argument is supplied.
 *
 * @deprecated Since 0.8.7. Use PackagePathResolver.
 */
final class ProjectPathResolver
{
    /**
     * Current package-path resolver to which the compatibility API delegates.
     */
    private PackagePathResolver $resolver;

    /**
     * Adapt the legacy root argument to PackagePathResolver.
     *
     * @param string $projectRoot Optional base directory; an empty value uses the installed package root.
     */
    public function __construct(string $projectRoot = '')
    {
        $this->resolver = new PackagePathResolver($projectRoot);
    }

    /**
     * Return the package base directory under the legacy accessor name.
     *
     * @return string Package root selected by the wrapped resolver.
     */
    public function projectRoot(): string
    {
        return $this->resolver->packageRoot();
    }

    /**
     * Resolve a path using the current package-root rules.
     *
     * @param string $path Nonblank absolute or relative path.
     * @return string Absolute input or path anchored to the selected package root.
     *
     * @throws \GeoLocator\Exception\ConfigurationException If the path is blank after trimming.
     */
    public function resolve(string $path): string
    {
        return $this->resolver->resolve($path);
    }
}

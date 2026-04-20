import {useCallback, useEffect, useState} from 'react';

interface AsyncResourceState<T> {
    data: T | null;
    loading: boolean;
    error: string | null;
}

export function useAsyncResource<T>(loadResource: (signal: AbortSignal) => Promise<T>) {
    const [reloadVersion, setReloadVersion] = useState(0);
    const [state, setState] = useState<AsyncResourceState<T>>({
        data: null,
        loading: true,
        error: null,
    });

    useEffect(() => {
        const abortController = new AbortController();

        setState((current) => ({
            data: current.data,
            loading: true,
            error: null,
        }));

        loadResource(abortController.signal)
            .then((data) => {
                if (abortController.signal.aborted) {
                    return;
                }

                setState({
                    data,
                    loading: false,
                    error: null,
                });
            })
            .catch((error: unknown) => {
                if (abortController.signal.aborted) {
                    return;
                }

                setState((current) => ({
                    data: current.data,
                    loading: false,
                    error: error instanceof Error ? error.message : 'Something went wrong while loading this resource.',
                }));
            });

        return () => abortController.abort();
    }, [loadResource, reloadVersion]);

    const reload = useCallback(() => {
        setReloadVersion((current) => current + 1);
    }, []);

    return {
        ...state,
        reload,
    };
}
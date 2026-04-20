export async function fetchJson<T>(input: RequestInfo | URL, init?: RequestInit): Promise<T> {
    const response = await fetch(input, init);
    const contentType = response.headers.get('content-type') || '';

    if (!response.ok) {
        throw new Error(`Request failed with ${response.status}`);
    }

    if (!contentType.includes('application/json')) {
        throw new Error('Request did not return JSON.');
    }

    return (await response.json()) as T;
}
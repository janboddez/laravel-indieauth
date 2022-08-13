<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ config('app.name') }}</title>

    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
    body,
    html {
        height: 100%;
    }

    body,
    button,
    input,
    select,
    textarea {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
        font-size: 1rem;
        line-height: 1.67;
    }

    .container {
        display: flex;
        align-items: center;
        justify-content: center;
        height: 100%;
    }

    .container > form {
        width: 90%;
        max-width: 700px;
    }
    </style>
</head>
<body>
    <div class="container">
        <form action="{{ url('/indieauth') }}" method="post">
            @csrf

            @if ($errors->any())
                <div class="alert alert-danger">
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <p>{{ __('You are attempting to log in with client :client_id.', ['client_id' => session('client_id')]) }}</p>

            @if (! empty($scopes))
                <p>{{ __('It is requesting the following scopes:') }}</p>

                <fieldset>
                    <legend>{{ __('Scopes') }}</legend>

                    @foreach($scopes as $scope)
                        <div class="form-group">
                            <label><input type="checkbox" name="scope[]" value="{{ $scope }}" checked> {{ ucfirst($scope) }}</label>
                        </div>
                    @endforeach
                </fieldset>
            @endif

            <p><button type="submit">{{ __('Submit') }}</button></p>
        </form>
    </div>
</body>
</html>

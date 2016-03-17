<?php

namespace Argo22\Modules\Core\Api;

/**
 * Utility class for holding API related constants
 */
final class Constants  {
	// custom RPC exception codes
	const RPC_ERROR_MISSING_TOKEN = -32000;
	const RPC_ERROR_INVALID_TOKEN = -32001;
	const RPC_ERROR_INVALID_CREDENTIALS = -32002;
	const RPC_ERROR_IDENTITY_NOT_FOUND = -32003;

	// user.signup validation errors
	const RPC_ERROR_INVALID_EMAIL = -32004;
	const RPC_ERROR_INVALID_PASSWORD = -32005;
	const RPC_ERROR_USERNAME_TAKEN = -32006;

	const RPC_ERROR_NOT_ALLOWED_FOR_VISITORS = -32007;
	const RPC_ERROR_INVALID_PARAMS_FORMAT = -32008;
	const RPC_ERROR_EMPTY_PARAM_VALUE = -32009;
	const RPC_ERROR_ITEM_NOT_FOUND = -32010;
	const RPC_ERROR_USER_ACCOUNT_EXPIRED = -32011;
	const RPC_ERROR_APP_VERSION_DEPRECATED = -32012;

	const RATING_MIN = 1;
	const RATING_MAX = 5;
}

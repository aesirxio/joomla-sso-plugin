<?php
/**
 * @package     AesirxSSO\Extension
 *
 * @copyright   Copyright (C) 2016 - 2023 Aesir. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 * @since       __DEPLOY_VERSION__
 */

namespace AesirxSSO\Extension;

use AesirxSSO\Table\UserXrefTable;
use Exception;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Authentication\AuthenticationResponse;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Event\CoreEventAware;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Response\JsonResponse;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserHelper;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\DI\ContainerAwareTrait;
use Joomla\Event\DispatcherInterface;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Plugin\System\Webauthn\PluginTraits\EventReturnAware;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use Throwable;

/**
 * @method CMSApplication getApplication()
 *
 * @package     AesirxSSO\Extension
 *
 * @since       __DEPLOY_VERSION__
 */
class AesirxSSOExtension extends CMSPlugin implements SubscriberInterface
{
	use EventReturnAware, DatabaseAwareTrait, CoreEventAware;

	protected $autoloadLanguage = true;

	/**
	 * Have I already injected CSS and JavaScript? Prevents double inclusion of the same files.
	 *
	 * @var     boolean
	 * @since   __DEPLOY_VERSION__
	 */
	private $injectedCSSandJS = false;

	/** @var GenericProvider|null */
	protected $provider;

	/**
	 * Constructor
	 *
	 * @param DispatcherInterface $subject     The object to observe
	 * @param array               $config      An optional associative array of configuration settings.
	 *                                         Recognized key values include 'name', 'group', 'params', 'language'
	 *                                         (this list is not meant to be comprehensive).
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function __construct(&$subject, $config = array())
	{
		parent::__construct($subject, $config);

		// Register a debug log file writer
		$logLevels = Log::ERROR | Log::CRITICAL | Log::ALERT | Log::EMERGENCY;

		if (\defined('JDEBUG') && JDEBUG)
		{
			$logLevels = Log::ALL;
		}

		Log::addLogger([
			'text_file'         => "aesirx_sso_system.php",
			'text_entry_format' => '{DATETIME}	{PRIORITY} {CLIENTIP}	{MESSAGE}',
		], $logLevels, ["aesirx_sso.system"]);
	}

	/**
	 * Injects the WebAuthn CSS and Javascript for frontend logins, but only once per page load.
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	private function addLoginCSSAndJavascript(): void
	{
		if ($this->injectedCSSandJS)
		{
			return;
		}

		// Set the "don't load again" flag
		$this->injectedCSSandJS = true;

		$wa = $this->getApplication()->getDocument()->getWebAssetManager();

		if (!$wa->assetExists('script', 'plg_system_aesirx_sso.login'))
		{
			$wa->registerScript('plg_system_aesirx_sso.login', 'plg_system_aesirx_sso/login.js', [], ['defer' => true], ['core']);
		}

		$wa->useScript('plg_system_aesirx_sso.login');

		$provider = $this->getProvider();

		// Fetch the authorization URL from the provider; this returns the
		// urlAuthorize option and generates and applies any necessary parameters
		// (e.g. state).
		$provider->getAuthorizationUrl();

		$state = $this->getApplication()->getName() . '-' . $provider->getState();

		// Get the state generated for you and store it to the session.
		$this->getApplication()->getSession()
			->set('plg_system_aesirx_login.oauth2state', $provider->getState());

		$wa->addInlineScript(
			'
			window.aesirxEndpoint="' . $this->params->get('endpoint') . '";
			window.aesirxClientID="' . $this->params->get('client_id') . '";
			window.aesirxSSOState="' . $state . '";
			',
			['position' => 'before'], [], ['plg_system_aesirx_sso.login']
		);

		Text::script('PLG_SYSTEM_AESIRX_SSO_LOGIN_LABEL');
		Text::script('PLG_SYSTEM_AESIRX_SSO_REDIRECTING');
		Text::script('PLG_SYSTEM_AESIRX_SSO_PROCESSING');
		Text::script('PLG_SYSTEM_AESIRX_SSO_REJECT');
	}

	/**
	 * Creates additional login buttons
	 *
	 * @param Event $event The event we are handling
	 *
	 * @return  void
	 *
	 * @see     AuthenticationHelper::getLoginButtons()
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function onUserLoginButtons(Event $event): void
	{
		/** @var string $form The HTML ID of the form we are enclosed in */
		list($form) = $event->getArguments();

		// Load necessary CSS and Javascript files
		$this->addLoginCSSAndJavascript();

		// Unique ID for this button (allows display of multiple modules on the page)
		$randomId = 'plg_system_aesirx_sso-' .
			UserHelper::genRandomPassword(12) . '-' . UserHelper::genRandomPassword(8);

		// Get local path to image
		$image = HTMLHelper::_('image', 'plg_system_aesirx_sso/aesirx_black.svg', '', '', true, true);

		// If you can't find the image then skip it
		$image = $image ? JPATH_ROOT . substr($image, \strlen(Uri::root(true))) : '';

		// Extract image if it exists
		$image = file_exists($image) ? file_get_contents($image) : '';

		$this->returnFromEvent($event, [
			[
				'label'              => 'PLG_SYSTEM_AESIRX_SSO_LOGIN_LABEL',
				'id'                 => $randomId,
				'data-webauthn-form' => $form,
				'svg'                => $image,
				'class'              => 'plg_system_aesirx_sso_login_button',
			],
		]);
	}

	/**
	 *
	 * @return GenericProvider
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected function getProvider(): GenericProvider
	{
		if (is_null($this->provider))
		{
			require_once JPATH_PLUGINS . '/system/aesirx_sso/vendor/autoload.php';
			$domain = rtrim($this->params->get('endpoint'), ' /') . '/index.php?api=oauth2&option=';

			$this->provider = new GenericProvider([
				'clientId'                => $this->params->get('client_id'),
				'clientSecret'            => $this->params->get('client_secret'),
				'redirectUri'             => Uri::getInstance()->toString(),
				'urlAuthorize'            => $domain . 'authorize',
				'urlAccessToken'          => $domain . 'token',
				'urlResourceOwnerDetails' => $domain . 'profile',
			]);
		}

		return $this->provider;
	}

	/**
	 * @return void
	 * @since __DEPLOY_VERSION__
	 */
	public function onAfterRoute(): void
	{
		$app     = $this->getApplication();
		$input   = $app->input;
		$session = $app->getSession();
		$state   = $input->getString('state');

		if (!empty($state))
		{
			list($clientName, $rawState) = explode('-', $state);

            // We are in different session folder
			if ($clientName != $app->getName())
			{
				$uri = Uri::getInstance();

				switch ($clientName)
				{
					case 'site':
						$uri->setPath('');
						break;
					default:
						$uri->setPath('/' . $clientName);
						break;
				}

				$app->redirect($uri->toString());
			}
            elseif ($rawState == $session->get('plg_system_aesirx_login.oauth2state'))
			{
				$code = $input->getString('code');

				if (!empty($code))
				{
					$accessToken = $this->getProvider()->getAccessToken('authorization_code', [
						'code' => $code,
					]);

					$app->getSession()
						->set('plg_system_aesirx_login.oauth2state', null);

					$response = array_replace(
						$accessToken->getValues(),
						$accessToken->jsonSerialize()
					);
				}
				else
				{
					$response = [
						'error'             => $input->getString('error'),
						'error_description' => $input->getString('error_description'),
					];
				}

				?>
                <script>
                    window.opener.sso_response = <?php echo json_encode($response) ?>;
                    window.close();
                </script>
				<?php
				$app->close();
			}
		}

		if ($input->getString('option') != 'aesirx_login')
		{
			return;
		}

		if ($input->getMethod() !== 'POST')
		{
			throw new Exception('Permission denied');
		}

		$result = [];

		header('Content-Type: application/json; charset=utf-8');
		$isFrontend = $app->isClient('site');

		try
		{
			switch ($input->get('task'))
			{
				case 'auth':
					$accessToken   = $input->post->get('access_token', [], 'array');
					$return        = base64_decode($input->post->get('return', '', 'BASE64'));
					$remember      = $input->post->getBool('remember', false);
					$resourceOwner = $this->getProvider()->getResourceOwner(new AccessToken($accessToken))->toArray();
					$remoteUserId  = $resourceOwner['profile']['id'];

					$session->set('plg_system_aesirx_login.remote_user_id', $remoteUserId);
					$session->set('plg_system_aesirx_login.remote_profile', $resourceOwner['profile']);

					Log::add(sprintf('Calling auth for %s', $accessToken['access_token']), Log::INFO, 'aesirx_sso.system');

					$xrefTable = new UserXrefTable($this->getDatabase());

					if (!$xrefTable->load(['aesirx_id' => $remoteUserId]))
					{
						$xrefTable->save([
							'aesirx_id'  => $remoteUserId,
							'created_at' => (new Date)->toSql(),
						]);
					}

					if ($xrefTable->get('user_id'))
					{
						$instance = new User;

						if (!$instance->load($xrefTable->get('user_id')))
						{
							throw new Exception(Text::_('PLG_SYSTEM_AESIRX_SSO_JOOMLA_ACCOUNT_NOT_FOUND'));
						}

						$response           = new AuthenticationResponse;
						$response->status   = Authentication::STATUS_SUCCESS;
						$response->type     = 'Aesirx';
						$response->username = $instance->username;
						$response->language = $instance->getParam('language');

						if ($isFrontend)
						{
							$options = [
								'remember' => $remember,
								'action'   => 'core.login.site',
							];

							// Check for a simple menu item id
							if (is_numeric($return))
							{
								$itemId = (int) $return;
								$return = 'index.php?Itemid=' . $itemId;

								if (Multilanguage::isEnabled())
								{
									$db    = $this->getDatabase();
									$query = $db->getQuery(true)
										->select($db->quoteName('language'))
										->from($db->quoteName('#__menu'))
										->where($db->quoteName('client_id') . ' = 0')
										->where($db->quoteName('id') . ' = :id')
										->bind(':id', $itemId, ParameterType::INTEGER);

									$language = $db->setQuery($query)
										->loadResult();

									if ($language !== '*')
									{
										$return .= '&lang=' . $language;
									}
								}
							}
                            elseif (!Uri::isInternal($return))
							{
								// Don't redirect to an external URL.
								$return = '';
							}

							// Set the return URL if empty.
							if (empty($return))
							{
								$return = 'index.php?option=com_users&view=profile';
							}

							// Set the return URL in the user state to allow modification by plugins
							$app->setUserState('users.login.form.return', $return);
						}
						else
						{
							$options = [
								'action' => 'core.login.admin',
							];

							if (!Uri::isInternal($return)
								|| strpos($return, 'tmpl=component') !== false)
							{
								$return = 'index.php';
							}
						}

						PluginHelper::importPlugin('user');
						$eventClassName = self::getEventClassByEventName('onUserLogin');
						$event          = new $eventClassName('onUserLogin', [(array) $response, $options]);
						$dispatched     = $app->getDispatcher()->dispatch($event->getName(), $event);
						$results        = !isset($dispatched['result']) || \is_null($dispatched['result']) ? [] : $dispatched['result'];

						// If there is no boolean FALSE result from any plugin the login is successful.
						if (in_array(false, $results, true) === false)
						{
							// Set the user in the session, letting Joomla! know that we are logged in.
							$session->set('user', $instance);

							// Trigger the onUserAfterLogin event
							$options['user']         = $instance;
							$options['responseType'] = $response->type;

							// The user is successfully logged in. Run the after login events
							$eventClassName = self::getEventClassByEventName('onUserAfterLogin');
							$event          = new $eventClassName('onUserAfterLogin', [$options]);
							$app->getDispatcher()->dispatch($event->getName(), $event);
						}
						else
						{
							// If we are here the plugins marked a login failure. Trigger the onUserLoginFailure Event.
							$eventClassName = self::getEventClassByEventName('onUserLoginFailure');
							$event          = new $eventClassName('onUserLoginFailure', [(array) $response]);
							$app->getDispatcher()->dispatch($event->getName(), $event);

							throw new Exception();
						}

						if ($isFrontend
							&& $options['remember'] == true)
						{
							$app->setUserState('rememberLogin', true);
						}

						Log::add(sprintf('Redirect %s to after-login page', $accessToken['access_token']), Log::INFO, 'aesirx_sso.system');

						if ($isFrontend)
						{
							$result['redirect'] = Route::_($app->getUserState('users.login.form.return'), false);
						}
						else
						{
							$result['redirect'] = $return;
						}
					}
					else
					{
						if (!$isFrontend
							|| ComponentHelper::getParams('com_users')->get('allowUserRegistration') == 0)
						{
							throw new Exception(Text::_('PLG_SYSTEM_AESIRX_SSO_ACCOUNT_NOT_FOUND'));
						}
						else
						{
							throw new Exception(Text::_('PLG_SYSTEM_AESIRX_SSO_ACCOUNT_NOT_FOUND_REGISTRATION_ALLOWED'));
						}
					}
					break;
			}
		}
		catch (\Throwable $e)
		{
			Log::add(sprintf("Error: %s", $e->getMessage()), Log::ERROR, 'aesirx_sso.system');
			http_response_code(500);
			$resp = new JsonResponse($e);

			if ($this->getApplication()->get('debug'))
			{
				$resp->trace = $e->getTrace();

				if ($e instanceof IdentityProviderException)
				{
					$resp->response = $e->getResponseBody();
				}
			}

			echo $resp;

			$app->close();
		}

		echo new JsonResponse($result);

		$app->close();
	}

	/**
	 * @since __DEPLOY_VERSION__
	 */
	public function onUserLogout(): void
	{
		if (!$this->getRemoteUserId())
		{
			return;
		}

		$this->getApplication()->getSession()->set('plg_system_aesirx_login.remote_user_id');
		$this->getApplication()->getSession()->set('plg_system_aesirx_login.user_id');
		$this->getApplication()->getSession()->set('plg_system_aesirx_login.remote_profile');
	}

	/**
	 * @return null|string
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected function getRemoteUserId(): ?string
	{
		return $this->getApplication()->getSession()->get('plg_system_aesirx_login.remote_user_id');
	}

	/**
	 * @return string|null
	 *
	 * @since __DEPLOY_VERSION__
	 */
	protected function getUserId(): ?string
	{
		return $this->getApplication()->getSession()->get('plg_system_aesirx_login.user_id');
	}

	/**
	 * @param Event $event Event
	 *
	 * @return void
	 * @since __DEPLOY_VERSION__
	 */
	public function onContentPrepareForm(Event $event): void
	{
		if (!$this->getRemoteUserId())
		{
			return;
		}

		/**  @var Form $form */
		list($form) = $event->getArguments();

		if ($form->getName() !== 'com_users.registration')
		{
			return;
		}

		$form->removeField('captcha');

		if (!$this->params->get('define_registration_fields', 0))
		{
			return;
		}

		$remoteProfile = $this->getApplication()->getSession()->get('plg_system_aesirx_login.remote_profile', []);

		foreach (['name', 'username', 'email'] as $field)
		{
			if (empty($remoteProfile[$field]))
			{
				continue;
			}

			switch ($field)
			{
				case 'email':
					$form->setFieldAttribute('email1', 'default', $remoteProfile[$field]);
					$form->setFieldAttribute('email2', 'default', $remoteProfile[$field]);
					break;
				default:
					$form->setFieldAttribute($field, 'default', $remoteProfile[$field]);
					break;
			}
		}
	}

	/**
	 * @param Event $event Event
	 *
	 * @return void
	 * @since __DEPLOY_VERSION__
	 */
	public function onUserAfterSave(Event $event): void
	{
		$remoteUserId = $this->getRemoteUserId();

		if (!$remoteUserId
			|| $this->getUserId())
		{
			return;
		}

		list($getProperties) = $event->getArguments();

		if (empty($getProperties['id']))
		{
			return;
		}

		$this->linkRemoteUserToLocal($getProperties['id']);
	}

	/**
	 * @param int $userId
	 *
	 *
	 * @throws Exception
	 * @since __DEPLOY_VERSION__
	 */
	protected function linkRemoteUserToLocal(int $userId): void
	{
		$remoteUserId = $this->getRemoteUserId();

		if (!$remoteUserId)
		{
			return;
		}

		$app           = $this->getApplication();
		$userXrefTable = new UserXrefTable($this->getDatabase());

		if ($userXrefTable->load(['user_id' => $userId]))
		{
			if ($userXrefTable->get('aesirx_id') == $remoteUserId)
			{
				$app->getSession()->set('plg_system_aesirx_login.user_id', $userId);

				return;
			}
			else
			{
				$app->getSession()->set('plg_system_aesirx_login.remote_user_id');
				$app->enqueueMessage(Text::_('PLG_SYSTEM_AESIRX_SSO_ACCOUNT_NOT_LINKED'), 'warning');

				// If current user already assigned to another aesirx account then do nothing
				return;
			}
		}

		$userXrefTable = new UserXrefTable($this->getDatabase());

		if (!$userXrefTable->load(['aesirx_id' => $remoteUserId]))
		{
			throw new Exception(Text::_('PLG_SYSTEM_AESIRX_SSO_AESIRX_SSO_ACCOUNT_NOT_FOUND'));
		}

		// Once user assigned then do not override it
		if ($userXrefTable->get('user_id'))
		{
			return;
		}

		if (!$userXrefTable->save(['user_id' => $userId]))
		{
			throw new Exception($userXrefTable->getError());
		}

		$app->getSession()->set('plg_system_aesirx_login.user_id', $userId);
		$app->enqueueMessage(Text::_('PLG_SYSTEM_AESIRX_SSO_ACCOUNT_LINKED'));
	}

	/**
	 * @param Event $event
	 *
	 *
	 * @throws Exception
	 * @since __DEPLOY_VERSION__
	 */
	public function onUserLogin(Event $event): void
	{
		$remoteUserId = $this->getRemoteUserId();

		if (!$remoteUserId
			|| $this->getUserId())
		{
			return;
		}

		list($user) = $event->getArguments();

		if (empty($user['username']))
		{
			return;
		}

		$id = (int) UserHelper::getUserId($user['username']);

		if (!$id)
		{
			return;
		}

		$this->linkRemoteUserToLocal($id);
	}

	/**
	 * Returns an array of events this subscriber will listen to.
	 *
	 * @return  array
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public static function getSubscribedEvents(): array
	{
		try
		{
			$app = Factory::getApplication();
		}
		catch (Throwable $e)
		{
			return [];
		}

		if (!in_array($app->getName(), ['site', 'administrator']))
		{
			return [];
		}

		return [
			'onUserAfterSave'      => 'onUserAfterSave',
			'onContentPrepareForm' => 'onContentPrepareForm',
			'onUserLoginButtons'   => 'onUserLoginButtons',
			'onAfterRoute'         => 'onAfterRoute',
			'onUserLogout'         => 'onUserLogout',
			'onUserLogin'          => 'onUserLogin',
		];
	}
}
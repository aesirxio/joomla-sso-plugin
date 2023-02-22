import axios, {AxiosError, AxiosResponse} from "axios";
import {aesirxSSO} from "aesirx-sso";

interface JoomlaText {
    _(key: string, def?: string | undefined): string,
}

interface JoomlaInterface {
    Text: JoomlaText,

    getOptions(key: string, def?: any): any,
}

interface AuthJson {
    redirect: string
}

interface JoomlaJson<T> {
    success: boolean
    message: string | null
    messages: Array<any> | null
    data: T
}

// @ts-ignore
const Joomla: JoomlaInterface = window.Joomla

interface AesirxButton extends HTMLButtonElement {
    changeContent(value: string, divSelector?: string): void
}

class LoginButtons {
    private buttons: HTMLCollectionOf<AesirxButton>;

    constructor(className: string) {
        // @ts-ignore
        this.buttons = document.getElementsByClassName(className)
        this.apply(function (n: AesirxButton) {
            n.innerHTML = n.innerHTML.replace(
                Joomla.Text._('PLG_SYSTEM_AESIRX_SSO_LOGIN_LABEL'),
                '<span class="aesirxLoginButtonMessage">' + Joomla.Text._('PLG_SYSTEM_AESIRX_SSO_LOGIN_LABEL') + '</span>');

            // @ts-ignore
            n.changeContent = function (value: string, selector: string = '.aesirxLoginButtonMessage'): void {
                const selected = this.querySelector(selector)
                if (selected) {
                    selected.innerHTML = value
                }
            }
        })
    }

    public apply(callback: (n: AesirxButton) => void) {
        for (var i = 0; i < this.buttons.length; i++) {
            callback(this.buttons[i])
        }
    }
}

interface AesirxResponse {
    access_token?: string
    error?: string
    error_description?: string
}

export async function run() {
    const buttons = new LoginButtons('plg_system_aesirx_sso_login_button')

    // @ts-ignore
    const rootUri: string = Joomla.getOptions('system.paths').baseFull;

    const onGetData = (btn: AesirxButton) => {
        return async (response: AesirxResponse) => {
            btn.disabled = false
            const returnInput = btn.form?.querySelector('input[name="return"]')
            const rememberInput = btn.form?.querySelector('input[name="remember"]')
            let returnVal = ''
            let remember = false

            if (returnInput instanceof HTMLInputElement) {
                returnVal = returnInput.value
            }

            if (rememberInput instanceof HTMLInputElement) {
                remember = rememberInput.checked
            }

            try {
                if (response.error) {
                    if (response.error_description) {
                        throw new Error(response.error_description)
                    } else {
                        throw new Error
                    }
                }

                const res: AxiosResponse<JoomlaJson<AuthJson>> = await axios<JoomlaJson<AuthJson>>({
                    method: 'post',
                    url: rootUri + 'index.php?option=aesirx_login&task=auth',
                    data: {
                        access_token: response,
                        return: returnVal,
                        remember: remember,
                    },
                    headers: {
                        'Content-Type': 'multipart/form-data',
                    }
                });

                if (res.status != 200) {
                    throw new Error
                }

                btn.disabled = false
                btn.changeContent(Joomla.Text._('PLG_SYSTEM_AESIRX_SSO_REDIRECTING'))

                window.location.href = res.data.data.redirect
            } catch (e) {
                btn.classList.replace("btn-secondary", "btn-warning")
                if (e instanceof AxiosError
                    && e.response && e.response.data && e.response.data.message) {
                    btn.changeContent(e.response.data.message)
                } else if (e instanceof Error && e.message) {
                    btn.changeContent(e.message)
                } else {
                    btn.changeContent(Joomla.Text._('PLG_SYSTEM_AESIRX_SSO_REJECT'))
                }

                btn.disabled = false
            }
        }
    }

    await aesirxSSO();

    buttons.apply(btn => {
        btn.addEventListener(
            'click',
            (event) => {
                btn.classList.replace("btn-warning", "btn-secondary")
                event.preventDefault()
                btn.disabled = true
                btn.changeContent(Joomla.Text._('PLG_SYSTEM_AESIRX_SSO_PROCESSING'))
                // @ts-ignore
                window.handleSSO(onGetData(btn))
            },
            false
        )
    })
}

run()

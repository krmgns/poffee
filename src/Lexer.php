<?php
declare(strict_types=1); namespace Poffee;

const RE_ID = '[a-z_]\w*';
const RE_STRING = '[\'"].*[\'"]';
const RE_OPR = '[\<\>\!\=\*/\+\-%\|\^\~]';
const RE_FUNCTION_ARGS = ' ,\w\.\=\(\)\[\]\'\"';

function isValidExpression($input) {
    // pre($input);
    $pattern[] = sprintf('(?<NOT>(\!)(\w+)|(not)\s+(\w+))');
    $pattern[] = sprintf('(?<OPR>(\w+)\s*(%s+)\s*(\w+)?)', RE_OPR);
    $pattern[] = sprintf('(?<CALLABLE_CALL>(?:(new)\s+)?([a-z_]\w*)\s*(\()(.*)(\)))');
    $pattern[] = sprintf('(?<METHOD_CALL>(?:(new)\s+)?([a-z_][%s]*)\s*(\.)([a-z_]\w*)\s*(\()(.*)(\)))', RE_FUNCTION_ARGS);
    $pattern[] = sprintf('(?<PROPERTY>(?:[a-z_]\w*)\.(?:[a-z_]\w*)(?:\..+)?)');
    $pattern[] = sprintf('(?<ARRAY>(?:(\[)(.*)(\])|([a-z_]\w*)(\[)(.+)(\])))');
    $pattern[] = sprintf('(?<SCOPE>(\()(.*)(\)))');
    $pattern = '~^(?:'. join('|', $pattern) .')$~ix';
    preg_match($pattern, $input, $matches);
    // pre($matches);
    $return = [];
    foreach ($matches as $key => $value) {
        if ($key !== 0 && $value !== '') {
            is_string($key) ? $return['type'] = $key : $return[] = $value;
        }
    }
    // var_dump($return); // var_dump
    return !empty($return) ? $return : null;
}

class Lexer extends LexerBase
{
    protected static $eol = PHP_EOL,
        $space = ' ',
        $indent = '    ',
        $indentLength = 4,
        $cache = []
    ;
    protected $file, $line;

    public function __construct(string $indent = null)
    {
        if ($indent) {
            self::$indent = $indent;
            self::$indentLength = strlen($indent);
        }
    }

    public function scan($file, $line, $input, $inputArray = null)
    {
        if (!isValidColon($input)) {throw new \Exception(sprintf('Sytax error in %s line %s, expecting ":" for the end of line!', $file, $line));}
        if (!isValidColonBody($input, $inputArray, $line)) {throw new \Exception(sprintf('Sytax error in %s line %s, expecting a proper colon body after colon-ending line!', $file, $line));}
        $lexer = new self(self::$indent);
        $lexer->file = $file;
        $lexer->line = $line;
        $pattern = '~(?:
              (?:(^\s+)?(?<![\'"])(//)\s*(.+))          # comment
            | (?:(declare)\s+([\'"].+[\'"]))            # declare
            | (?:(module)\s+([a-z_]\w*)\s*(:))          # module (namespace)
            | (?:(use)\s+(.+))                          # use
            | (?:(const)\s+([a-z_]\w*)\s*(=)\s*(.+))    # const
            | (?:                                       # objects
                (object)
                (?:\s+(abstract|final)\s*)?             # descriptor
                (?:\s+(class|interface|trait)\s+([a-z_]\w*))   # class, interface, trait
                (?:\s*(>)\s+([a-z_]\w*))?               # extends
                (?:\s*(>>)\s+([a-z_](?:[\w,\s]*)))?     # implements
              (:))
            | (?:(^\s+)                                 # const
                (?:(const)
                    (?:\s+(@|@@))?                      # private, protected
                       \s+([a-z_]\w*)                   # name
                    (?:\s*(=)\s*(.+))                   # value
                )
              )
            | (?:(^\s+)                                 # property
                (?:(var)
                    (?:\s+(@|@@))?                      # private, protected
                    (?:\s+(static))?                    # static
                       \s+([a-z_]\w*)                   # name
                    (?:\s*(=)\s*(.+))?                  # value
                )
              )
            | (?:(^\s+)                                 # method
                (?:(fun)
                    (?:\s+(@|@@))?                      # private, protected
                    (?:\s*(final|abstract|static)?      # final or abstract, static
                       \s*(static|final|abstract)?      # static, final or abstract
                    )?
                       \s+([a-z_]\w*)                   # name
                    (?:\s*(\(.*\)))                     # arguments
                )
                (:)                                     # colon
                (?:\s*([a-z_]\w*))?                     # return type
              )
            | (?:(^\s+)?                                # function
                (?:([a-z_]\w*)\s*(=)\s*)?               # anon name
                (?:(fun)
                   (?:\s+([a-z_]\w*))?                  # real name
                   (?:\s*(\(.*\)))                      # arguments
                   (:)                                  # colon
                   (?:\s*([a-z_]\w*))?                  # return type
                )
              )
            | (?:(^\s+)?(?:(if|elseif|for|ise?(?:\s*(?:not)?)) # if, .. for, is ..
                \s+(.+)|(else))\s*(:))
            | (?:(^\s+)?(require|include(?:_once)?)\s*(.*))                # require, include ..
            | (?:(^\s+)?(return)\s*(.*))                # return
            | (?:(^\s+)?(var)\s+([a-z_]\w*)\s*(=)\s*(.+))       # assign
            #| (?:(^\s+)?(.+))
        )~ix';
        $matches = $lexer->getMatches($pattern, $input);
        pre($matches);
        return $lexer->generateTokens($matches);
    }
    public function scanFunctionExpression($line, $input)
    {
        $lexer = new self(self::$indent);
        $lexer->line = $line;
        $pattern = '~(?:
            (\()? # open parentheses
                \s*((\?)?[a-z_]\w*)?             # typehint
                \s*(&)?                          # reference
                \s*([a-z_]\w*)                   # variable name
                \s*(?:(=)\s*(\w+|[\'"].*[\'"]))? # variable default value
            (\))? # close parentheses
        )~ix';
        $matches = $lexer->getMatches($pattern, $input);
        // pre($matches);
        return $lexer->generateTokens($matches);
    }

    public function generateTokens(array $matches)
    {
        $tokens = [];
        foreach ($matches as $match) {
            $value = is_array($match) ? $match[0] : $match;
            if ($value === self::$space) continue; // ?
            $token  = [];
            $indent = null;
            $length = strlen($value);
            if ($value !== self::$eol && ctype_space($value)) {
                if ($length < self::$indentLength or $length % self::$indentLength !== 0) {
                    throw new \Exception(sprintf('Indent error in %s line %s!', $this->file, $this->line));
                }
                $type = T_INDENT;
                // $token['size'] = $length; // / self::$indentLength;
            } else {
                $type = $this->getType($value);
            }
            // $start = $match[1]; $end = $start + $length;
            $token += ['value' => $value, 'type' => $type, 'line' => $this->line,
                // 'length' => $length, 'start' => $start, 'end' => $end, 'children' => null
            ];
            $tokens[] = $token;
        }
        $tokens = new Tokens($tokens);
        if (!$tokens->isEmpty()) {
            while ($token = $tokens->next()) {
                $prev = $token->prev(); $prevType = $prev ? $prev->type : null;
                $next = $token->next(); $nextType = $next ? $next->type : null;
                $tokenType = $token->type; $tokenValue = $token->value;
                if ($tokenType === T_COMMENT) {
                    $next->type = T_COMMENT_CONTENT;
                } elseif ($tokenType === T_DECLARE) {
                    $next->type = T_EXPR;
                } elseif ($tokenType === T_MODULE) {
                    $next->type = T_MODULE_ID;
                } elseif ($tokenType === T_USE) {
                    $next->type = T_EXPR;
                } elseif ($tokenType === T_OBJECT) {
                    while (($t = $tokens->next()) && $t->value !== C_COLON) {
                        if ($t->type) continue;
                        if ($t->value === C_EXTENDS) {
                            $t->type = T_EXTENDS;
                        } elseif ($t->value === C_IMPLEMENTS) {
                            $t->type = T_IMPLEMENTS;
                        } else {
                            $t->type = T_OBJECT_ID;
                        }
                    }
                } elseif ($tokenType === T_CONST) {
                    while (($t = $tokens->next()) && $t->value !== C_ASSIGN) {
                        if ($t->type) continue;
                        if ($t->value === C_PRIVATE) {
                            $t->type = T_PRIVATE;
                        } elseif ($t->value === C_PROTECTED) {
                            $t->type = T_PROTECTED;
                        } else {
                            $t->type = T_CONST_ID;
                        }
                    }
                } elseif ($tokenType === T_VAR) {
                    while (($t = $tokens->next()) && $t->value !== C_EOL) {
                        // pre($t->value);
                        if ($t->type) continue;
                        if ($t->value === C_PRIVATE) {
                            $t->type = T_PRIVATE;
                        } elseif ($t->value === C_PROTECTED) {
                            $t->type = T_PROTECTED;
                        // } elseif (isValidID($t->value)) {
                        //     $t->type = T_VAR_ID; // bunu kaldir, dogrudan var expr yap
                        } else {
                            pre($t->value);
                            $t->type = T_VAR_EXPR;
                        }
                    }
                } elseif ($tokenType === T_FUN) {
                    while (($t = $tokens->next()) && $t->value !== C_EOL) {
                        if ($t->type) continue;
                        if ($t->value === C_PRIVATE) {
                            $t->type = T_PRIVATE;
                        } elseif ($t->value === C_PROTECTED) {
                            $t->type = T_PROTECTED;
                        } elseif ($t->value[0] === '(') {
                            $t->type = T_FUN_ARGS_EXPR;
                            if ($t->prev->prev->type === T_ASSIGN) {
                                $token->type = T_FUN_ANON; // fix token type
                            } else {
                                $t->prev->type = T_FUN_ID;
                            }
                        } elseif (isValidID($t->value)) {
                            $t->type = T_FUN_RET_TYPE;
                        }
                    }
                } elseif (
                    $tokenType === T_IF || $tokenType === T_ELSE_IF ||
                    $tokenType === T_IS || $tokenType === T_IS_NOT ||
                    $tokenType === T_ISE || $tokenType === T_ISE_NOT ||
                    $tokenType === T_FOR
                ) {
                    $next->type = T_EXPR;
                } elseif ($tokenType === T_RETURN) {
                    if (!$next->type) {
                        $next->type = T_RETURN_EXPR;
                    }
                } elseif (!$tokenType) {
                    if ($next) {
                        if ($next->type === T_ASSIGN) {
                            $token->type = T_VAR_ID;
                        }
                    }
                    // if ($nextType === T_ASSIGN) {
                    //     $tokenType = T_VAR_ID;
                    // // } elseif ($expression = isValidExpression($tokenValue)) {
                    // //     $tokenType = getTokenTypeFromConst($expression['type'].'_expr');
                    // //     if ($tokenType) {
                    // //         $token->children = $this->generateTokens(array_slice($expression, 2));
                    // //     }
                    // } elseif ($prevType === T_ASSIGN) {
                    //     $tokenType = T_EXPR;
                    // }
                }

                // if no type error?
            }
        }
        return $tokens;
    }

    public function getType($value)
    {
        $value = strval($value);
        switch ($value) {
            case self::$eol:    return T_EOL;
            case self::$space:  return T_SPACE;
            case self::$indent: return T_INDENT;
            case '=':           return T_ASSIGN;
            case '.':           return T_DOT;
            case ':':           return T_COLON;
            case ',':           return T_COMMA;
            case '?':           return T_QUESTION;
            case '//':          return T_COMMENT;
            case '(':           return T_OPEN_PRNT;
            case ')':           return T_CLOSE_PRNT;
            case '[':           return T_OPEN_BRKT;
            case ']':           return T_CLOSE_BRKT;

            // bunlar icin getTokenTypeFromConst() kullan sonra
            case 'declare': return T_DECLARE;
            case 'module': return T_MODULE;
            case 'use': return T_USE;
            case 'abstract': return T_ABSTRACT;
            case 'final': return T_FINAL;
            case 'object': return T_OBJECT;
            case 'class': return T_CLASS;
            case 'interface': return T_INTERFACE;
            case 'trait': return T_TRAIT;
            case 'const': return T_CONST;
            case 'var': return T_VAR;
            case 'fun': return T_FUN;
            case 'this': return T_THIS;
            case 'return': return T_RETURN;

            case 'static': return T_STATIC;
            case 'global': return T_GLOBAL;

            case 'null': return T_NULL;
            case 'true': case 'false': return T_BOOL;
            case 'if': return T_IF; case 'else': return T_ELSE; case 'elseif': return T_ELSE_IF;
            case 'for': return T_FOR; case 'in': return T_IN;
            case 'is': return T_IS; case 'ise': return T_ISE;
            case 'is not': return T_IS_NOT; case 'ise not': return T_ISE_NOT;
            case 'require': return T_REQUIRE; case 'require_once': return T_REQUIRE_ONCE;
            case 'include': return T_INCLUDE; case 'include_once': return T_INCLUDE_ONCE;

            case 'die': case 'echo': case 'empty': case 'eval': case 'exit':
            case 'isset': case 'list': case 'print': case 'unset': case '__halt_compiler': return T_FUN_ID;

            default:
                // burasi sikintili "a" + "b" true verir
                $fChar = $value[0]; $lChar = substr($value, -1);
                if ($fChar === "'" && $lChar === "'") {
                    return T_STRING;
                }
                if ($fChar === '"' && $lChar === '"') {
                    return T_STRING;
                }
                if ($fChar === '[' && $lChar === ']') {
                    return T_ARRAY_EXPR;
                }
                if ($fChar === '(' && $lChar === ')') {
                    // return T_EXPR; ?? // yukarda isValidExpression sorgusunu engelliyor
                }
                if (is_numeric($value)) {
                    return T_NUMBER;
                }
                if (preg_match(RE_OPR, $value)) {
                    return T_OPR;
                }
        }
        return null;
    }
    public function getMatches($pattern, $input)
    {
        return preg_split($pattern, $input, -1,
            PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE);
    }
}

function getTokenTypeFromConst($name) {
    // $name = sprintf('%s\\T_%s', __namespace__, strtoupper($name));
    // if (defined($name)) {
    //     return $name; // @tmp // constant($name);
    // }
    // @tmp
    $name = strtoupper("t_{$name}");
    if (defined(__namespace__ .'\\'. $name)) {
        return $name; // @tmp // constant($name);
    }
    throw new \Exception("Undefined constant: '$name'"); // @debug

}
